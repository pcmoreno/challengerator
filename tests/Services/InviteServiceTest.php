<?php
declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\Auth\EmailVerification;
use App\Entity\Auth\User;
use App\Entity\Challenge\Challenge;
use App\Exception\BusinessLogicException;
use App\Exception\ChallengeDoesNotExistException;
use App\Services\InviteService;
use App\Tests\Repository\InMemory\InMemoryChallengeRepository;
use App\Tests\Repository\InMemory\InMemoryEmailVerificationRepository;
use App\Tests\Repository\InMemory\InMemoryTransaction;
use App\Tests\Repository\InMemory\InMemoryUserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class InviteServiceTest extends TestCase
{
    private InMemoryUserRepository $users;
    private InMemoryEmailVerificationRepository $verifications;
    private InMemoryChallengeRepository $challenges;
    private InviteService $service;

    protected function setUp(): void
    {
        $this->users         = new InMemoryUserRepository();
        $this->verifications = new InMemoryEmailVerificationRepository();
        $this->challenges    = new InMemoryChallengeRepository();
        foreach (['rally', 'challengeY'] as $slug) {
            $this->challenges->save(Challenge::create($slug, $slug, 'secret'));
        }

        $mailer = $this->createMock(MailerInterface::class);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/accept/token');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(fn(User $user, string $pw) => 'hashed_' . $pw);

        $this->service = new InviteService(
            $this->users,
            $this->verifications,
            $this->challenges,
            new InMemoryTransaction(),
            $mailer,
            $urlGenerator,
            $hasher,
        );
    }

    public function test_invite_does_not_create_a_user_row(): void
    {
        $this->service->invite('new@example.com', 'rally');

        $this->assertNull($this->users->findByEmail('new@example.com'));
        $this->assertSame(1, $this->verifications->count());
    }

    public function test_invite_removes_prior_pending_verification_before_creating_new_one(): void
    {
        $this->service->invite('new@example.com', 'rally');
        $this->service->invite('new@example.com', 'rally');

        $this->assertSame(1, $this->verifications->count(), 'Re-invite must leave exactly one pending verification');
    }

    public function test_invite_throws_when_email_already_member_of_challenge(): void
    {
        $user = new User('alice');
        $user->setEmail('alice@example.com');
        $this->users->save($user);
        $this->users->seedChallenge('rally');
        $this->users->addToChallenge($user, 'rally');

        $this->expectException(BusinessLogicException::class);
        $this->service->invite('alice@example.com', 'rally');
    }

    public function test_invite_creates_token_for_verified_user_not_yet_in_challenge(): void
    {
        $alice = new User('alice');
        $alice->setEmail('alice@example.com');
        $alice->verify();
        $this->users->save($alice);
        $this->users->seedChallenge('rally');

        $this->service->invite('alice@example.com', 'rally');

        $this->assertSame(1, $this->verifications->count(), 'A token must be issued so the verified user can confirm via link');
        $this->assertFalse($this->users->isInChallenge($alice, 'rally'), 'Not enrolled until the user clicks the link');

        $tokens = $this->verifications->findPendingByEmailAndChallenge('alice@example.com', 'rally');
        $this->assertSame($alice, $tokens[0]->getUser(), 'Token must carry the verified user FK');
    }

    public function test_invite_throws_when_verified_user_already_member_of_challenge(): void
    {
        $alice = new User('alice');
        $alice->setEmail('alice@example.com');
        $alice->verify();
        $this->users->save($alice);
        $this->users->seedChallenge('rally');
        $this->users->addToChallenge($alice, 'rally');

        $this->expectException(BusinessLogicException::class);
        $this->service->invite('alice@example.com', 'rally');
    }

    public function test_acceptVerifiedUserInvite_enrolls_and_deletes_token(): void
    {
        $alice = new User('alice');
        $alice->setEmail('alice@example.com');
        $alice->verify();
        $this->users->save($alice);
        $this->users->seedChallenge('rally');

        $verification = $this->makeVerificationWithUser($alice, 'rally');

        $this->service->acceptVerifiedUserInvite($verification);

        $this->assertSame(0, $this->verifications->count(), 'Token must be deleted after acceptance');
        $this->assertTrue($this->users->isInChallenge($alice, 'rally'), 'Verified user must be enrolled');
    }

    public function test_requestSelfRegistration_throws_account_exists_for_verified_user_not_in_challenge(): void
    {
        $alice = new User('alice');
        $alice->setEmail('alice@example.com');
        $alice->verify();
        $this->users->save($alice);
        $this->users->seedChallenge('rally');

        $this->expectException(\App\Exception\AccountExistsException::class);
        $this->service->requestSelfRegistration('alice@example.com', 'alice', 'rally');
    }

    public function test_requestSelfRegistration_throws_business_logic_for_verified_user_already_in_challenge(): void
    {
        $alice = new User('alice');
        $alice->setEmail('alice@example.com');
        $alice->verify();
        $this->users->save($alice);
        $this->users->seedChallenge('rally');
        $this->users->addToChallenge($alice, 'rally');

        $this->expectException(BusinessLogicException::class);
        $this->service->requestSelfRegistration('alice@example.com', 'alice', 'rally');
    }

    public function test_acceptInvite_throws_and_deletes_legacy_null_fk_token_for_verified_user(): void
    {
        $alice = new User('alice');
        $alice->setEmail('alice@example.com');
        $alice->verify();
        $this->users->save($alice);

        $this->users->seedChallenge('rally');
        $verification = $this->makeVerification('alice@example.com', 'rally');

        try {
            $this->service->acceptInvite($verification, 'alice', 'newpass');
            $this->fail('Expected BusinessLogicException was not thrown');
        } catch (BusinessLogicException) {
            // expected
        }

        $this->assertSame(0, $this->verifications->count(), 'Stale token must be deleted');
        $this->assertNull($alice->getPassword(), 'Password must not be changed');
    }

    public function test_acceptInvite_creates_new_user_when_no_existing_email_match(): void
    {
        $this->users->seedChallenge('rally');
        $verification = $this->makeVerification('new@example.com', 'rally');

        $this->service->acceptInvite($verification, 'newbie', 'secret');

        $user = $this->users->findByEmail('new@example.com');
        $this->assertNotNull($user);
        $this->assertSame('newbie', $user->getUsername());
        $this->assertSame('hashed_secret', $user->getPassword());
        $this->assertTrue($user->isVerified());
        $this->assertSame(0, $this->verifications->count(), 'Verification must be deleted after accept');
    }

    public function test_acceptInvite_reuses_existing_user_when_email_matches(): void
    {
        $existing = new User('alice');
        $existing->setEmail('alice@example.com');
        $this->users->save($existing);

        $this->users->seedChallenge('challengeY');
        $verification = $this->makeVerification('alice@example.com', 'challengeY');

        $this->service->acceptInvite($verification, 'alice', 'newpass');

        $this->assertSame($existing, $this->users->findByEmail('alice@example.com'));
        $this->assertSame('hashed_newpass', $existing->getPassword());
        $this->assertTrue($existing->isVerified());
    }

    public function test_acceptInvite_lets_existing_user_keep_their_own_username(): void
    {
        $alice = new User('alice');
        $alice->setEmail('alice@example.com');
        $this->users->save($alice);

        $this->users->seedChallenge('challengeY');
        $verification = $this->makeVerification('alice@example.com', 'challengeY');

        // alice tries to accept for challenge Y using her existing username — must not throw
        $this->service->acceptInvite($verification, 'alice', 'pw');

        $this->assertSame('alice', $alice->getUsername());
    }

    public function test_acceptInvite_throws_when_username_belongs_to_a_different_user(): void
    {
        $bob = new User('bob');
        $bob->setEmail('bob@example.com');
        $this->users->save($bob);

        $this->users->seedChallenge('rally');
        $verification = $this->makeVerification('alice@example.com', 'rally');

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('username is already taken');
        $this->service->acceptInvite($verification, 'bob', 'pw');
    }

    public function test_acceptInvite_throws_when_challenge_was_deleted(): void
    {
        // challenge 'gone' is never seeded in the user repository
        $verification = $this->makeVerification('new@example.com', 'gone');

        $this->expectException(ChallengeDoesNotExistException::class);
        $this->service->acceptInvite($verification, 'newbie', 'pw');
    }

    public function test_acceptInvite_throws_friendly_error_on_duplicate_email_race(): void
    {
        $driverException = new \Doctrine\DBAL\Driver\PDO\Exception('Duplicate entry', '23000', 1062);
        $uniqueEx = new UniqueConstraintViolationException($driverException, null);

        $throwingUsers = $this->createMock(\App\Repository\UserRepositoryInterface::class);
        $throwingUsers->method('findByEmail')->willReturn(null);
        $throwingUsers->method('findByUsername')->willReturn(null);
        $throwingUsers->method('save')->willThrowException($uniqueEx);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/accept/token');
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(fn(User $user, string $pw) => 'hashed_' . $pw);

        $racingService = new InviteService(
            $throwingUsers,
            $this->verifications,
            $this->challenges,
            new InMemoryTransaction(),
            $this->createMock(MailerInterface::class),
            $urlGenerator,
            $hasher,
        );

        $verification = $this->makeVerification('new@example.com', 'rally');
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('already accepted');
        $racingService->acceptInvite($verification, 'newbie', 'pw');
    }

    private function makeVerification(string $email, string $challengeName): EmailVerification
    {
        $verification = new EmailVerification(
            null,
            $email,
            bin2hex(random_bytes(16)),
            new \DateTimeImmutable('+48 hours'),
            EmailVerification::TYPE_JOIN_CHALLENGE,
            $challengeName,
        );
        $this->verifications->save($verification);
        return $verification;
    }

    private function makeVerificationWithUser(User $user, string $challengeName): EmailVerification
    {
        $verification = EmailVerification::forJoinChallenge(
            $user,
            $user->getEmail() ?? '',
            bin2hex(random_bytes(16)),
            new \DateTimeImmutable('+48 hours'),
            $challengeName,
        );
        $this->verifications->save($verification);
        return $verification;
    }
}
