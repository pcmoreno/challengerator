<?php
declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\Auth\EmailVerification;
use App\Entity\Auth\User;
use App\Exception\BusinessLogicException;
use App\Services\InviteService;
use App\Tests\Repository\InMemory\InMemoryEmailVerificationRepository;
use App\Tests\Repository\InMemory\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class InviteServiceTest extends TestCase
{
    private InMemoryUserRepository $users;
    private InMemoryEmailVerificationRepository $verifications;
    private InviteService $service;

    protected function setUp(): void
    {
        $this->users         = new InMemoryUserRepository();
        $this->verifications = new InMemoryEmailVerificationRepository();

        $mailer = $this->createMock(MailerInterface::class);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/accept/token');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(fn(User $u, string $pw) => 'hashed_' . $pw);

        $this->service = new InviteService(
            $this->users,
            $this->verifications,
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
        $this->users->addToChallenge($user, 'rally');

        $this->expectException(BusinessLogicException::class);
        $this->service->invite('alice@example.com', 'rally');
    }

    public function test_acceptInvite_creates_new_user_when_no_existing_email_match(): void
    {
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

        // alice is being invited; she tries to claim username 'bob' which belongs to bob
        $verification = $this->makeVerification('alice@example.com', 'rally');

        $this->expectException(BusinessLogicException::class);
        $this->service->acceptInvite($verification, 'bob', 'pw');
    }

    private function makeVerification(string $email, string $challengeName): EmailVerification
    {
        $v = new EmailVerification(
            null,
            $email,
            bin2hex(random_bytes(16)),
            new \DateTimeImmutable('+48 hours'),
            EmailVerification::TYPE_JOIN_CHALLENGE,
            $challengeName,
        );
        $this->verifications->save($v);
        return $v;
    }
}
