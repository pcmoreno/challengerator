# Code Review — Challengerator (current `master`)

**Stack reviewed:** PHP 8.3 / Symfony 6.4.37 LTS / Doctrine ORM 2.20 / MariaDB 10.11 (primary store) / CouchDB 3 (alternative repo, currently unwired) / Hotwire Turbo 8.0.23 / Stimulus 3.2.2 / Tailwind via `symfonycasts/tailwind-bundle` / AssetMapper.

**Auth model:** Symfony Security with `App\Entity\Auth\User` + form_login authenticator on `/voter-login` (login throttling: 5/hour). Admins authenticate via `AuthController::adminLogin`, password-verify against `challenge.admin_password` bcrypt hash, then session stores `admin_challenges` array. `ROLE_SUPER_ADMIN` bypasses all admin checks via `AdminGuardTrait`.

**Scope of this review:** security, UX logic, business logic, PHP code quality, Turbo/Stimulus integration.

**Sentinel comparison with previous review:** The pre-pull review on the f2b50c5 snapshot was largely obsolete after this rewrite. Most of the prior CRITICAL findings (plaintext admin password, public Couch routes, debug-DSN leak, dump-die in production, missing CSRF, IP token in URL) are **fixed**. This review focuses on what's still wrong on current `master` (HEAD `e8c6d55`).

Severity labels: **CRITICAL** (security or data integrity), **HIGH** (correctness / business logic), **MEDIUM** (code quality, maintainability), **LOW** (UX polish).

---

## 1. CRITICAL — Security

### 1.1 `DriveImageController` is an unauthenticated open image proxy
`src/Controller/DriveImageController.php` — route `/image/{fileId}` has **no auth, no rate limit, no size cap**, and:
- Fetches arbitrary fileIds from `https://drive.google.com/thumbnail?sz=w1200&id=...`.
- Caches the result on local disk forever (30-day client cache, **no server-side TTL**).
- Validates `fileId` against `^[A-Za-z0-9_\-]{10,100}$` — good for path traversal, useless for abuse.

Attacker can:
1. **Enumerate fileIds** to fill `var/drive_image_cache/` until disk is full. There's no size cap on the cache directory.
2. Use your server as a **free, anonymous CDN** for any Drive file made public anywhere. The bandwidth bill is yours.
3. **Cache-poison**: Google returns HTML on error/auth pages. `file_put_contents` writes whatever bytes come back. The next request reads those bytes from disk and serves them under whatever `mime_content_type` infers — including HTML labeled `image/jpeg`. No content validation.

Fix: gate the endpoint to authenticated users (or signed/short-lived URLs), enforce a per-IP rate limit, cap cached file size, validate the response is actually image bytes (`getimagesizefromstring`), and put a cache eviction policy in place.

### 1.2 `DriveImageController` first-call file race / missing directory
`src/Controller/DriveImageController.php:30` — `file_put_contents($cachePath, $contents)` with no `mkdir -p`. On a fresh deployment the cache directory doesn't exist; the call fails silently (returns false, no exception). The next line, `mime_content_type($cachePath)`, returns false on a missing file. Then `file_get_contents($cachePath)` (line 36) returns `false` → Response body is `false`/empty. The user sees a broken image but the controller logs nothing.

Also: not atomic — two concurrent first-time requests race; one or both can write a truncated file that is then cached forever. Write to a temp file and `rename()`.

### 1.3 Google OAuth credentials stored in plaintext JSON
`storage_resource.credentials` is `JSON NULL` in MariaDB (`migrations/Version20260511000000.php`). It contains `access_token`, **`refresh_token`**, `cached_email`, `drive_folder_id`. A DB dump exposes long-lived refresh tokens for every admin's Google account — full Drive access per the scopes (`drive.file` + `userinfo.email`).

Fix: encrypt at column level using a key from `kernel.secret` or, better, Sodium with a key bound to a KMS. Symfony has `symfony/encryption` patterns; Doctrine encryption bundles exist.

### 1.4 OAuth nonce comparison is non-constant-time
`src/Controller/DriveOAuthController.php:62`: `if ($storedNonce !== $nonce)`. Use `hash_equals($storedNonce, $nonce)`. Same issue (different field) in `ChallengeService::isVoterTokenValid` and `::isAdminTokenValid` (lines 225–235) — those are dead code now (see 3.2) but worth fixing while you remove them.

### 1.5 Self-registration has no human-verification
The previous review flagged this; CAPTCHA (`gregwar/captcha-bundle`) was **removed entirely** in the rewrite. Now `addSelfRegisteredVoterForChallengePage` is guarded only by:
- Sliding-window rate limiter (5 per IP per hour, `config/packages/rate_limiter.yaml`).
- One-time-correct `selfRegistrationCode`.
- DB-side IP uniqueness check.

This is fine for a casual deployment but trivially bypassable from a botnet or via Tor. If you don't want CAPTCHA back, at least add `hcaptcha`/Cloudflare Turnstile, or tie self-registration to a per-challenge invite that admins must hand out individually.

### 1.6 IP-based uniqueness is broken behind a reverse proxy
`config/packages/framework.yaml` has **no `trusted_proxies` / `trusted_headers` configuration**. The nginx service in `docker-compose.yml` proxies to PHP-FPM, so `Request::getClientIp()` returns the nginx container's address — every voter shares one IP. `DoctrineVoterRepository::hasVoterFromIpForChallenge` then blocks every voter after the first.

Additionally, `AuthController::addSelfRegisteredVoterForChallengePage` line 82 does `$request->getClientIp() ?? ''` — empty string is then stored as the user's IP, and every subsequent request with a null IP is treated as a duplicate.

Fix: configure `framework.trusted_proxies: '127.0.0.1,REMOTE_ADDR'` and `framework.trusted_headers: ['x-forwarded-for','x-forwarded-host','x-forwarded-proto']`, and have nginx set those headers correctly.

### 1.7 Admin session has no per-challenge logout or expiry
`AuthController::adminLogin` adds a challenge to `session.admin_challenges`. There is no admin logout link, no per-challenge expiry, no idle timeout, no "step-up" re-auth requirement. The session lives for `session.cookie_lifetime` (default: browser session). On a shared machine, leaving the tab open leaves admin access open.

Voter logout (`voter_logout`) invalidates the entire session, so admin entries are wiped then — but voters log out via a link that **doesn't exist in the UI**. So in practice, admin sessions live until the browser is closed.

Add: per-challenge admin-logout link, session idle timeout (`session.cookie_lifetime: 1800` + `session.gc_maxlifetime`), optional re-auth before sensitive actions (delete car, reset voter, start challenge).

### 1.8 Custom POST forms in `voterDashboard.html.twig` omit CSRF tokens
`templates/voter/voterDashboard.html.twig` has three standalone `<form method="post">` blocks (lines 23, 54, 73 — toggle self-registration, reset voter, start challenge) with **no CSRF token field**. The controllers (`toggleSelfRegistrationForChallenge`, `resetVotesForVoterOnChallenge`, `startChallenge`) also don't validate CSRF.

`session.cookie_samesite: lax` (`framework.yaml:16`) means cross-origin POSTs don't send the session cookie, so practical CSRF risk is low. But:
- Lax allows top-level GET; if a future change downgrades to POST→GET or another field flips, the protection collapses.
- The `deleteCar` form in `templates/car/carDashboard.html.twig:60` is in the same boat.
- The `drive_oauth_disconnect` form (`carDashboard.html.twig:18-23`) **does** include a CSRF token. Inconsistent.

Fix: every state-mutating standalone form needs `<input type="hidden" name="_token" value="{{ csrf_token('some_id') }}">`, and the controller needs `$this->isCsrfTokenValid('some_id', $request->request->get('_token'))`. Defense in depth.

### 1.9 `changePassword` flow is an unauthenticated credential-stuffing oracle
`AuthController::changePasswordForVoter` (`/voter/changePass`) is in `firewalls.main` with no `access_control` rule, so it's anonymous. Anyone with username+current password can change it. There's no rate limit on this endpoint (the rate limiter is only on `addSelfRegisteredVoterForChallengePage`), no CAPTCHA, no lockout.

This is a high-value oracle: it tells you whether a given (username, password) pair is correct. With the login throttler at 5/hour, this becomes the easier credential-checker.

Fix: apply the same `login_throttling` policy or attach a `RateLimiterFactory` here. Better: require a logged-in voter session and remove the username field.

### 1.10 `ChangePasswordType` shows the current password in plaintext
`src/Form/ChangePasswordType.php:19` — `->add('currentPass', TextType::class)`. The current password is visible as the user types and visible to screen viewers/over-the-shoulder. (`newPass` is correctly `PasswordType`.) Trivial fix; same finding as previous review — not addressed in rewrite.

### 1.11 Drive uploads grant `anyone` reader permission on every car image
`src/Services/GoogleDriveService.php:79-82`:
```php
$drive->permissions->create($fileId, new Google_Service_Drive_Permission([
    'type' => 'anyone',
    'role' => 'reader',
]));
```
Every uploaded car image becomes publicly readable on Google Drive via `https://drive.google.com/uc?id=...`. Required for the `DriveImageController` proxy to fetch via thumbnail URL, but worth two flags:
- The image is reachable directly from Drive, **bypassing your `/image/` cache entirely**. So the abuse vector in 1.1 is plus the public Drive URL exposure.
- A leak of any car `fileId` (via your DB, logs, dashboards, error pages) = anyone in the world can view the image. Document this clearly to admins; consider scoping to `domain` + service account instead.

---

## 2. HIGH — Business logic / correctness

### 2.1 Vote-on-cars still allows same-car double submission
`src/Services/ChallengeService.php:152-186` — `voteOnCars`:
```php
$carIds = explode('XXX', $cars);
$voter = $this->voterRepository->find($userId);
$unvotedCars = $voter->getUnvotedCarsForChallenge($challengeId);
foreach ($carIds as $carId) {
    if (!in_array($carId, $unvotedCars)) {
        throw new \DomainException('Car already voted');
    }
}
```
No check that `count($carIds) === 2` and `$carIds[0] !== $carIds[1]`. Submitting `cars=ID1XXXID1` passes the membership check and proceeds to Elo-update **the same car against itself**. Outcome `LeftWins` then pushes the rating up by ~50 and (because both ratings are equal) the math is symmetric — net effect is a fixed swing per call. Cheating becomes a matter of constructing the POST body.

Fix:
```php
if (count($carIds) !== 2 || $carIds[0] === $carIds[1]) {
    throw new \InvalidArgumentException('Pair must contain two distinct cars');
}
```

This finding is identical to the previous review's #2.1 and **was not addressed in the rewrite**.

### 2.2 No optimistic locking on `DbCar.rating` — lost-update race
`DbCar` (`src/Entity/Doctrine/DbCar.php`) has no `#[ORM\Version]` column. Two voters voting concurrently on the same pair will:
- Both read the same rating value.
- Both compute their Elo update independently.
- Both flush; the second `UPDATE car SET rating = X WHERE id = ?` overwrites the first silently.

Effect: ratings drift slower than they should under concurrency, and votes are functionally dropped.

Fix: add `#[ORM\Version]` to a new `int $version = 1` column on `DbCar`. On `OptimisticLockException`, retry the read-compute-write cycle. With Symfony 6's `EntityManagerInterface::wrapInTransaction`, do this in a transaction.

### 2.3 `voteOnCars` is not transactional
Three separate `$repository->save()` calls each invoke `em->flush()`. If the voter save fails (constraint violation, deadlock), the car ratings are already persisted but the voter's queue state is not updated. The voter can replay the same pair.

Fix:
```php
$this->em->wrapInTransaction(function () use (...) {
    $this->carRepository->save($carA);
    $this->carRepository->save($carB);
    $this->voterRepository->save($voter);
});
```
(`ChallengeService` doesn't have the `EntityManagerInterface` though — currently it only knows about repository interfaces. Either inject `EntityManagerInterface` or add a transactional helper to the repository contracts.)

### 2.4 `DoctrineVoterRepository::syncQueue` deletes + re-inserts all queue rows on every save
`src/Repository/Doctrine/DoctrineVoterRepository.php:152-205`:
```php
$existing = $this->em->getRepository(DbVoterCarQueue::class)->findBy(['user' => $user->getId()]);
foreach ($existing as $row) {
    $this->em->remove($row);
}
$this->em->flush();
// ... then re-persists every row from $voter->getChallengeRounds()
```
Every vote (or any voter save) bulk-deletes the entire queue and bulk-inserts it again. Two flushes per save, O(N) row churn per vote, lots of dead tuples in MariaDB. Combined with 2.2/2.3, also widens the conflict window for concurrent voters.

Fix: targeted update. The `voteOnCars` flow knows exactly which two cars moved from pending to voted; just `UPDATE voter_car_queue SET status='voted' WHERE user_id=? AND challenge_id=? AND car_id IN (?,?)`. Add a method to the repo interface.

### 2.5 `voteOnCars` random pair selection is biased
`src/Services/ChallengeService.php:136-140`:
```php
while (count($selectedCarIds) < 2) {
    $random = rand(0, count($carsToVote) - 1);
    $selectedCarIds[] = $carsToVote[$random];
    array_splice($carsToVote, $random, 1);
}
```
`rand()` is fine for non-crypto, but the `array_splice`+`rand` pattern over a shrinking array biases toward earlier indices on the first pick (PHP `rand` LCG quirk plus the bounded retry). Use `array_rand($carsToVote, 2)` — unbiased, idiomatic, two lines.

### 2.6 Elo `K = 100` is extreme
`src/Services/RatingService.php:11` — `K = 100`. Chess-standard K is 20–40. With K=100, a single match can swing ~50 points. Low-traffic challenges become noise-dominated.

Fix: make K a per-challenge field, default 32. Optionally bracket K by total matches played (high K early, low K late) for faster convergence.

### 2.7 `Voter::createForChallenge` is still used for self-registration but bypasses Symfony's password hasher
`src/Services/ChallengeService.php:60` calls `Voter::createForChallenge($voterName, $password, ...)`. That entity does `password_hash($pass, PASSWORD_BCRYPT)` directly (`src/Entity/Challenge/Voter.php:27`). Meanwhile, the invite flow (`InviteService::acceptInvite`) uses Symfony's `UserPasswordHasherInterface` which respects `security.yaml`'s `password_hashers: App\Entity\Auth\User: 'bcrypt'`. The two paths produce compatible hashes today, but if you ever migrate to Argon2id in `security.yaml`, the self-registration path will silently keep using bcrypt while invited users get Argon2id.

Fix: thread `UserPasswordHasherInterface` into `ChallengeService` (or the part of it handling self-reg) and hash via it.

### 2.8 `DoctrineCarRepository::findMany` silently drops missing IDs
`src/Repository/Doctrine/DoctrineCarRepository.php:32-46`:
```php
return array_values(array_filter(array_map(fn($id) => $indexed[$id] ?? null, $ids)));
```
If a car was deleted between the challenge fetch and the car fetch, the returned array is shorter than `$ids`. `ChallengeService::voteOnCars:166`:
```php
[$carA, $carB] = $this->carRepository->findMany($carIds);
```
If `findMany` returns one element, `$carB` is undefined (in PHP this is `null` with a notice). Then `$carB->getChallengeId()` throws a TypeError. Validate `count($result) === count($ids)` in the repo, or throw a typed exception.

### 2.9 `getTwoCarsToBeVotedByUser` returns car objects but `_remaining.html.twig` prints car IDs
`templates/voting/_remaining.html.twig:7-9`:
```twig
{% for car in carsNotVoted %}
    <li>{{ car }}</li>
```
`carsNotVoted` is the unvoted IDs array (raw UUID strings), so `{{ car }}` prints UUIDs. To a voter, this means "Cars left to compare:" followed by a column of opaque hex strings — meaningless. Either show a counter (already shown at the top) and drop the list, or resolve to car names.

### 2.10 `ChallengeService::verifyLogin` is dead code that pretends to be live auth
`src/Services/ChallengeService.php:188-217` — `verifyLogin` is no longer called by any production code path (voter login is handled by Symfony's `form_login`, admin login by `AuthController::adminLogin`). However:
- Line 197: `if ($login->user === Role::ADMIN && $login->pass === $challenge->getOwner())` — `getOwner()` now returns a bcrypt hash (set by `createNewChallenge:33`), so this `===` comparison can never match. The admin branch is unreachable.
- The test suite `tests/Services/ChallengeServiceTest.php` still tests this method, and the tests **pass only because they construct `Challenge::create($name, 'secret')` with a plaintext owner** — bypassing the production hashing path. So the tests are green but they're verifying behaviour that production never exercises.

Fix: delete `verifyLogin`, `doLoginForUser`, `doLoginForAdmin`, `isVoterTokenValid`, `isAdminTokenValid`. Then delete the `Voter::token`, `Voter::tokenExpirationDate`, `Voter::generateToken`, `Voter::getToken`, `Challenge::adminToken`, `Challenge::adminTokenExpirationDate`, `Challenge::generateAdminToken`, `Challenge::getAdminToken` fields/methods — none persist to the Doctrine schema and none are read by the production controllers. Then delete the matching tests.

### 2.11 `AppFixtures` crashes on `bin/console doctrine:fixtures:load`
`src/DataFixtures/AppFixtures.php:78`:
```php
$voter->addCarsToSelf($carIds, 'dev-rally', true);
```
But `Voter::addCarsToSelf` is now `(array $cars, string $challengeId): void` — **two parameters**. Passing a third `true` is a `TypeError`. Also: the file is committed but `.gitignore:38` excludes `/src/DataFixtures/`, so future edits won't show up in `git status`. Either fix it and `git rm --cached` the ignore line, or delete the fixture entirely.

### 2.12 `InviteService::invite` persists `User` rows for invitations that never get accepted
`src/Service/InviteService.php:34-38`:
```php
} else {
    $user = new User('invite_' . bin2hex(random_bytes(8)));
    $user->setEmail($inviteEmail);
    $this->em->persist($user);
}
```
A pending invite creates a `User` row with a placeholder username. If the invitee never clicks the link, that row stays forever. Over time the `user` table fills with `invite_xxxxxxxx` ghosts. There's no cleanup cron, no expiry on the User itself (only on the `EmailVerification`).

Fix: either (a) defer User creation to `acceptInvite`, storing the email on the `EmailVerification` directly until then; or (b) add a cleanup job that deletes Users with no `verifiedAt`, no related `challenge_user` rows, and no active `EmailVerification`.

Related: re-inviting the same email accumulates `EmailVerification` rows (no `DELETE ... WHERE user = ? AND type = ?` before the new persist). Add it.

### 2.13 `User.email` has no unique constraint
`src/Entity/Auth/User.php:25-26` — `email` is nullable but **not unique**. Two Users can share an email. Combined with `InviteService::invite`'s `findOneBy(['email' => $inviteEmail])`, this returns *some* user (Doctrine doesn't guarantee which), which can route invites to the wrong existing account.

Fix: `#[ORM\Column(type: 'string', length: 254, nullable: true, unique: true)]` + a migration with a `UNIQUE` index, plus a deduplication step before adding the constraint.

### 2.14 ~~`DoctrineVoterRepository::save` has a TOCTOU on username~~ — RESOLVED (not applicable)
Self-registration is one-per-IP; the invite flow is email-based. For the race to trigger, the same person would need to click two invite links simultaneously in separate tabs. Not a realistic scenario — no fix needed.

### 2.15 `Challenge` and `Voter` domain entities carry vestigial Couch state
`src/Entity/Challenge/Challenge.php` has `id = 'info'` (legacy Couch doc id), `revision`, `toCouchDocument()`, `fromCouchDocument()`. Voter has the same. With MariaDB now the primary store and `DoctrineChallengeRepository::toDomain` constructing via `Challenge::fromArray`, the Couch helpers are unused. This is two parallel codebases that must be kept in sync (e.g. when adding a field, you must remember to update both `toCouchDocument()` and `toArray()` and the Doctrine entity).

Fix: decide. If Doctrine is the production store, delete the Couch repos (`src/Repository/Couch/`), delete `Voter::fromCouchDocument`/`Voter::toCouchDocument`, delete `Challenge::fromCouchDocument`/`Challenge::toCouchDocument`, drop `php-on-couch` from `require-dev`.

### 2.16 `startChallenge` server-side has no idempotency / no confirmation
`templates/voter/voterDashboard.html.twig:73-79` uses `data-turbo-confirm="..."` — that's a **client-side** confirmation. A curl POST to `/challenge/initialize/{challengeName}` (admin-session required) skips the confirm and wipes every voter's progress for the challenge.

Fix: either require a typed token in the POST body (e.g. type the challenge name to confirm), or only allow re-initialization when `isActive === false`.

### 2.17 `RoundOfComparisons` data corruption guard
`src/Entity/Challenge/Voter.php:160-168` throws `ImpossibleVotedCarsAmountException` when the voted-cars count is odd. Good defensive code — but the exception is unhandled by the dashboard rendering, so an odd count crashes the whole voter dashboard page for the admin. Should be a flash warning + skip the row.

---

## 3. MEDIUM — PHP code quality

### 3.1 Two storage backends with parallel implementations
`src/Repository/Couch/` (4 implementations) and `src/Repository/Doctrine/` (5 implementations) plus `tests/Repository/InMemory/` (5 fakes). `config/services.yaml:53-63` wires the Doctrine variants; the Couch ones are present but unused (commented-out service definitions at lines 33-51).

If the migration to Doctrine is complete, delete Couch. If both stores are intended, document which is canonical and write integration tests against the inactive one.

### 3.2 Dead authentication code
Already noted in #2.10. Summary of code that should be deleted:
- `ChallengeService::verifyLogin`, `doLoginForUser`, `doLoginForAdmin`, `isVoterTokenValid`, `isAdminTokenValid` (`src/Services/ChallengeService.php:188-235, 302-314`)
- `Voter::token`, `Voter::tokenExpirationDate`, `Voter::generateToken`, `Voter::getToken`, `Voter::getTokenExpirationDate` (`src/Entity/Challenge/Voter.php:16-17, 139-158`)
- `Challenge::adminToken`, `Challenge::adminTokenExpirationDate`, `Challenge::generateAdminToken`, `Challenge::getAdminToken` (`src/Entity/Challenge/Challenge.php:18-19, 97-102, 182-206`)
- `Voter::id` UUIDv6 generation in `createForChallenge` is harmless but creates and immediately discards a UUID before Doctrine assigns an int id (see 3.4).

### 3.3 `Voter::id` is sometimes a UUID, sometimes a stringified int
`Voter::createForChallenge` (`src/Entity/Challenge/Voter.php:23`) assigns `Uuid::v6()->jsonSerialize()`. `DoctrineVoterRepository::save` (`src/Repository/Doctrine/DoctrineVoterRepository.php:73`) only re-finds an existing User if `ctype_digit($rawId)` — so the freshly-generated UUID is dropped and Doctrine assigns an auto-increment int. After reconstitution (`DoctrineVoterRepository::toDomain:144`), `Voter::id` is then `(string)$user->getId()`.

So the Voter id is:
- UUID v6 string between `createForChallenge()` and the first `save()`
- Stringified int after persistence

Code that compares Voter ids works by accident because both are strings. Document this or unify (e.g. use a UUID column on `user` as PK, or keep the Voter domain id as the int).

### 3.4 `verifyLogin` is dead code but tests still exercise it with plaintext owner
See #2.10. The test class `tests/Services/ChallengeServiceTest.php` uses `Challenge::create($name, 'secret')` so the owner is plaintext; the test then exercises `verifyLogin` and asserts admin role. In production, `createNewChallenge` always hashes — so the test isn't reflective. Fix or delete.

### 3.5 `ChangePasswordType.currentPass` is `TextType`
Already in 1.10. Same root issue, just listed here under code-quality too.

### 3.6 `Outcome::tryFrom` is good; remaining string→enum translations are not
`Outcome::tryFrom` is used in `voteOnCars` — modern and clean. Compare with `Role` (still a class with const strings, `App\Entity\Auth\Role`). Convert `Role` to a PHP enum. It would surface `Role::VOTER_OF_A_DIFFERENT_CHALLENGE` as either an enum case or as a redundant string (the new auth path returns it from `verifyLogin`, which is dead code anyway — see #3.2).

### 3.7 Inconsistent method naming
- `ChallengeService::AddVoterToChallengeFromIp` (capital A, `src/Services/ChallengeService.php:51`).
- Most other methods camelCase.

Fix: rename to `addVoterToChallengeFromIp` and update `AuthController::addSelfRegisteredVoterForChallengePage:78`.

### 3.8 `ChallengeService::initializeChallenge` silently re-uses cars list across all voters
`src/Services/ChallengeService.php:106-109`:
```php
foreach ($this->voterRepository->findMany($challenge->getVoters()) as $voter) {
    $voter->addCarsToSelf($carIds, $challengeName);
    $this->voterRepository->save($voter);
}
```
`addCarsToSelf` now (in the rewrite) **wipes** the existing pending + voted queue for that challenge (no `clean` parameter — always resets). So calling `startChallenge` mid-tournament wipes everyone's progress. Same risk as #2.16; this is the underlying mechanism.

### 3.9 Unused/boilerplate Stimulus controller
`assets/controllers/hello_controller.js` is the maker-bundle scaffolding. Delete.

### 3.10 Inline `<script>` in `base.html.twig` blocks tight CSP
`templates/base.html.twig:8`:
```html
<script>if (localStorage.getItem('theme') === 'auto') document.documentElement.dataset.theme = 'auto';</script>
```
Forces `'unsafe-inline'` if you adopt CSP. Move to a small `theme-init.js` module loaded via importmap, or use a per-response nonce.

### 3.11 Missing security headers
`config/packages/framework.yaml` doesn't set any response headers. No CSP, no HSTS, no `X-Frame-Options`, no `X-Content-Type-Options`. Add via an `EventSubscriber` on `kernel.response`, or in nginx (`docker-compose/nginx/*.conf` — not reviewed).

### 3.12 `Voter::createForChallenge` throws bare `\Exception`
`src/Entity/Challenge/Voter.php:29` — `throw new \Exception(...)`. You have a typed exception hierarchy in `src/Exception/` (`BusinessLogicException`, `ImpossibleVotedCarsAmountException`). Use it, or use `\RuntimeException`. Same pattern in `addCarsToSelf:96`, `setCarsToVotedForChallenge:117`, `Challenge::addCarToChallenge`, etc.

### 3.13 `Voter::generateToken` uses mutable `\DateTime`
Should be `\DateTimeImmutable`. Dead code, so this is doubly moot — delete instead.

### 3.14 `DoctrineVoterRepository::toDomain` performs N+1 fetches
For each voter loaded, two extra queries (queue rows + registered-challenges). When listing all voters for a challenge (admin dashboard), this fans out badly. Either eager-load with joins, or build the domain Voter set in a single query.

### 3.15 `DriveOAuthController::callback` reassigns `$credentials` via closure
`src/Controller/DriveOAuthController.php:78`:
```php
static function (array $newCredentials) use (&$credentials): void { $credentials = $newCredentials; }
```
Works, but the bind-by-reference + static closure trick is non-obvious. A small dedicated mutable wrapper or simply not using `static` would read clearer.

### 3.16 `DriveImageController` does `file_get_contents` twice
`src/Controller/DriveImageController.php:30, 36` — once to write the cached file, once to read it back into the Response body. The first call already has `$contents`; reuse it. Avoids reading from disk and the empty-body bug from 1.2.

### 3.17 `Migrations/Version20260508194705.php` — composite primary key reordered later
The initial migration creates `challenge_user (user_id, challenge_id)` PK; Version20260510100000 reorders to `(challenge_id, user_id)`. Fine, but the original migration should be amended (it's never been run on prod yet, given the dates 2026-05-08 → 2026-05-11). Squashing the four migrations into one would be cleaner before the first prod deploy.

### 3.18 No PHP-CS-Fixer / PHPStan / Psalm config
Mentioned in the previous review and still missing.

### 3.19 `ApiController.php` and `DefaultController.php` were deleted but nothing replaced their tests
Tests directory has new `DriveOAuthControllerTest` (240 lines) and `ChallengeServiceTest` (470 lines) — good coverage growth — but no controller tests for `ChallengeController`, `AuthController`, or `VotingController`. Functional tests on the form_login flow, admin POST endpoints, and the Turbo Stream response path would catch most of the remaining issues in this review.

---

## 4. UX / Frontend Logic

### 4.1 Turbo Stream response correctness — looks good
`src/Controller/VotingController.php:54-61` checks `$request->getPreferredFormat() === TurboBundle::STREAM_FORMAT` and renders `voting/_stream.html.twig` only for Turbo-aware clients. Non-Turbo clients get a normal 302 redirect. This is the correct Symfony UX Turbo pattern.

The stream template `templates/voting/_stream.html.twig` replaces both `#car-pair` and `#cars-remaining` frames — correct.

`assets/controllers/voting_controller.js` disables submit buttons during `turbo:submit-start` and re-enables on `turbo:submit-end` — prevents double-vote on slow networks. Good.

### 4.2 Prefetch risk under Turbo 8
Turbo 8 auto-prefetches hovered `<a>` links by default. The remaining `<a>` links across the app:
- `path('addCarToChallengeFormPage', ...)` — GET, safe
- `path('addVoterToChallengeFormPage', ...)` — GET, safe
- `path('addSelfRegisteredVoterToChallengeFormPage', ...)` — GET, safe (only renders signup form)
- `path('drive_oauth_connect', ...)` — **GET**, and `connect()` does `$request->getSession()->set('drive_oauth_nonce_' . $challengeName, $nonce)` and redirects to Google. A hover-prefetch will set a session nonce that may overwrite a real flow in progress. Low-impact but worth knowing.

Recommendation: add `data-turbo-prefetch="false"` on the Drive-connect link, or convert it to a form.

### 4.3 `data-turbo-confirm` is client-side only
On `templates/voter/voterDashboard.html.twig:74` and `templates/car/carDashboard.html.twig:62`. A direct curl skips it. See 2.16 — server-side confirmation is needed for `startChallenge`.

### 4.4 `_remaining.html.twig` prints car UUIDs
See 2.9. UX-dead.

### 4.5 `ChangePasswordType.currentPass` plaintext field
See 1.10.

### 4.6 Form theme is custom (`templates/form/theme.html.twig`) but not reviewed in depth
Worth a small audit to ensure all form widgets are accessible (label associations, error rendering, focus management).

### 4.7 Email template uses inline styles
`templates/email/voter_invite.html.twig` — inline CSS is correct for HTML email, but the entire file uses fixed-width 600px tables with no plain-text alternative. Add a `.text.twig` sibling for `TemplatedEmail::textTemplate(...)`; some mail clients suppress HTML and your invite link disappears.

### 4.8 No visible voter logout link
`security.yaml` configures `voter_logout` (`logout: path: voter_logout`), but no template links to it. Voters and admins can't end their session from the UI.

### 4.9 The "Open Challenge" client confirm wording
"Everyone will be able to vote again. Continue?" — the action wipes every voter's pending **and** voted lists, not just makes them eligible. The wording understates the destructiveness.

---

## 5. Turbo / Hotwire integration assessment

### What's right
- Bundles installed and registered (`config/bundles.php:15-17`).
- AssetMapper + importmap.php with pinned versions (`@hotwired/turbo: 8.0.23`, `@hotwired/stimulus: 3.2.2`).
- `VotingController::voteForCarForUser` correctly negotiates `TurboBundle::STREAM_FORMAT` and falls back to a 302 redirect for non-Turbo clients.
- Stimulus controllers are scoped, use `connect`/`disconnect` hooks, clean up event listeners. `voting_controller.js` and `delete_confirm_controller.js` integrate with Turbo events cleanly.
- The custom delete-confirm modal uses Turbo's `turbo:confirm` event to bridge `data-turbo-confirm` into a styled dialog rather than the browser's native `confirm()`. Nicely done.

### What's wrong / risky
- Turbo 8 prefetch is on by default. Any state-mutating `<a>` link will fire silently on hover. Currently all mutations are `<form method="post">`, but it's a single change away from breaking — codify with `meta name="turbo-prefetch" content="false"` site-wide if you want safety, or per-link `data-turbo-prefetch="false"` for the Drive-connect link (#4.2).
- `data-turbo-confirm` is the only confirmation guard for destructive operations. Curl bypasses it (#2.16, #4.3).
- The `voting_controller` disables submit buttons during in-flight requests — good for double-submit prevention on slow networks, but **only protects the client side**. The server still needs same-pair / replay guards (#2.1, #2.3).
- `image_full_size_controller.js` creates a singleton overlay on `connect()` and removes it on `disconnect()`. If multiple `data-controller="image-full-size"` elements are on the page (the voting card has one such on the wrapper, `templates/voting/_car_pair.html.twig:1`), multiple overlays are created. Each click opens its own overlay; closing one leaves the other(s) attached. Minor: scope the overlay creation to a global controller or use Turbo Streams to ensure only one root element carries the controller.
- `hello_controller.js` boilerplate still present (#3.9).
- `theme_controller.js` writes to `localStorage` and reads `document.documentElement.dataset.theme` — fine, but the bootstrap inline script in `base.html.twig:8` duplicates the same logic to avoid FOUC. The duplicate needs to stay in sync with `theme_controller`. Consider a single module that both the inline script and Stimulus controller import (importmap module imports work pre-DOMReady).

---

## 6. Configuration & Infrastructure

### 6.1 `docker-compose.yml` mounts host `~/.ssh` into containers
Same finding as before — line 14, `~/.ssh:/root/.ssh`. Leaks personal SSH keys. Use SSH agent forwarding instead.

### 6.2 CouchDB still exposed on host port 5984 with default `admin:admin`
`docker-compose.yml:34-45`. Now there's a real password set (`COUCHDB_USER=admin`, `COUCHDB_PASSWORD=admin`) — but it's `admin`/`admin`. And it's still bound to host port. If you're not actively using CouchDB (Doctrine repos are wired), remove this service or unbind the port.

### 6.3 MariaDB also exposed on host port 3306 with weak credentials
`docker-compose.yml:19-32`. `MYSQL_ROOT_PASSWORD=root`, `MYSQL_PASSWORD=challengerator`, port bound to host. For dev that's expected; ensure your prod compose doesn't reuse this file. The committed `.env.test:6` confirms `DATABASE_URL=mysql://challengerator:challengerator@...` — same creds in source control.

### 6.4 Mailpit catches mail in dev but no equivalent for prod is configured
`docker-compose.yml:47-53` adds Mailpit on port 8025. No prod mailer configuration visible — `config/packages/mailer.yaml` not reviewed in depth, but you'll need to switch DSN per env.

### 6.5 No `trusted_proxies` configuration
See #1.6. This affects rate limiting and self-registration IP uniqueness.

### 6.6 No CI workflow
Still missing `.github/workflows/`. With ~1500 LOC of test code now, CI is overdue.

### 6.7 `prod/monolog.yaml` `fingers_crossed` excludes 404/405 but logs everything else from `error` level
Fine, but consider also excluding 401/403 if those are expected (e.g. anonymous probes on admin routes). Currently they'd flood `var/log/prod.log`.

### 6.8 `Dockerfile` still installs `pdo_mysql` and `gd` etc. — looks reasonable
PHP 8.3 base image, modern. No `EXPOSE` or `CMD` set; assumed to be orchestrated externally. WORKDIR is set now.

### 6.9 `Makefile` and `README.md` were added — not reviewed in this pass
Worth a follow-up to ensure they're accurate and the README documents the auth model and admin/super-admin flows.

---

## Top 10 fixes (priority order)

1. **Gate `/image/{fileId}` behind authentication + rate limit + content-type validation + size cap (#1.1, #1.2).** Easiest open door.
2. **Encrypt `storage_resource.credentials` at rest (#1.3).** Refresh tokens in plaintext = full Drive access after any DB compromise.
3. **Configure `trusted_proxies` in `framework.yaml` (#1.6).** Behind nginx, every voter currently looks the same to your code.
4. **Validate the voting pair (`array_unique`, `count === 2`) in `voteOnCars` (#2.1).** Identical to last review's #2.1 — still unaddressed.
5. **Add `#[ORM\Version]` to `DbCar` + wrap `voteOnCars` in a transaction with retry (#2.2, #2.3).** Closes the lost-update race.
6. **Add CSRF tokens to the standalone admin POST forms** in `voterDashboard.html.twig` and `carDashboard.html.twig` (#1.8).
7. **Rate-limit (and ideally re-auth-gate) `/voter/changePass` (#1.9).** It's currently a free credential-stuffing oracle.
8. **Delete the dead token-based auth code** in `ChallengeService`, `Voter`, `Challenge` (#2.10, #3.2). Also delete or fix `AppFixtures` (#2.11).
9. **Server-side confirm/idempotency on `startChallenge` (#2.16).** Curl POST currently wipes all in-progress voting silently.
10. **Either commit to Couch or to Doctrine and delete the unused repo + Couch helpers on the domain entities (#2.15, #3.1).** Two parallel storage codebases will diverge.

---

## Quick / Medium / Deep split

**Quick wins (≤30 min):**
- Delete `assets/controllers/hello_controller.js` (#3.9).
- Change `currentPass` to `PasswordType` (#1.10, #4.5).
- `array_rand` for pair selection (#2.5).
- Same-pair check in `voteOnCars` (#2.1).
- Replace `===` with `hash_equals` in OAuth nonce check (#1.4).
- Remove dead `verifyLogin`/token methods (#2.10, #3.2).
- Drop `_remaining.html.twig` UUID list (#4.4) or replace with names.

**Medium (1–4h):**
- `#[ORM\Version]` + transaction wrap on `voteOnCars` (#2.2, #2.3).
- CSRF tokens on standalone admin forms (#1.8).
- Auth/rate-limit gate on `/image/` (#1.1).
- Mkdir + atomic-write fix on `DriveImageController` (#1.2).
- Configure `trusted_proxies` (#1.6).
- Rate limiter on `changePasswordForVoter` (#1.9).
- Cleanup of stale `User` invite ghosts + dedup of `EmailVerification` (#2.12).
- `User.email` unique constraint with deduplication migration (#2.13).
- Server-side `startChallenge` confirmation token (#2.16).

**Deep (multi-day):**
- Encrypt OAuth credentials at rest (#1.3) — column-level encryption is a small repo + key management decision.
- Decide Couch vs Doctrine; delete one (#2.15, #3.1).
- Migrate from `Voter::createForChallenge` direct bcrypt → `UserPasswordHasherInterface` everywhere (#2.7).
- Eager loading + targeted queue mutations in `DoctrineVoterRepository` (#2.4, #3.14).
- Security headers (CSP, HSTS, X-Frame-Options) + CSP-clean theme bootstrap (#3.10, #3.11).
- CI workflow, PHPStan/Psalm, controller-level functional tests (#3.18, #3.19, #6.6).

---

*Generated against `e8c6d55` (master) on 2026-05-18. Hand to implementation agent for staged fixes.*
