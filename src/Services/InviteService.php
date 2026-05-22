<?php
declare(strict_types=1);

namespace App\Services;

use App\Entity\Auth\EmailVerification;
use App\Entity\Auth\User;
use App\Exception\BusinessLogicException;
use App\Repository\EmailVerificationRepositoryInterface;
use App\Repository\TransactionInterface;
use App\Repository\UserRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class InviteService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EmailVerificationRepositoryInterface $verificationRepository,
        private TransactionInterface $transaction,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function invite(string $inviteEmail, string $challengeName): void
    {
        $existingUser = $this->userRepository->findByEmail($inviteEmail);

        if ($existingUser !== null && $existingUser->isVerified()) {
            if ($this->userRepository->isInChallenge($existingUser, $challengeName)) {
                throw new BusinessLogicException("$inviteEmail is already a member of $challengeName.");
            }
            // Verified user not yet in this challenge: enroll directly, no credential setup needed.
            $this->userRepository->addToChallenge($existingUser, $challengeName);
            $this->sendAddedToChallengeNotification($inviteEmail, $challengeName);
            return;
        }

        if ($existingUser !== null && $this->userRepository->isInChallenge($existingUser, $challengeName)) {
            throw new BusinessLogicException("$inviteEmail is already a member of $challengeName.");
        }

        foreach ($this->verificationRepository->findPendingByEmailAndChallenge($inviteEmail, $challengeName) as $old) {
            $this->verificationRepository->delete($old);
        }

        $token = bin2hex(random_bytes(32));
        $verification = new EmailVerification(
            null,
            $inviteEmail,
            $token,
            new \DateTimeImmutable('+48 hours'),
            EmailVerification::TYPE_JOIN_CHALLENGE,
            $challengeName,
        );
        $this->verificationRepository->save($verification);

        $link = $this->urlGenerator->generate(
            'voter_accept_invite',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $message = (new TemplatedEmail())
            ->from(new Address('noreply@challengerator.local', 'Challengerator'))
            ->to($inviteEmail)
            ->subject("You've been invited to join $challengeName")
            ->htmlTemplate('email/voter_invite.html.twig')
            ->context([
                'link' => $link,
                'challengeName' => $challengeName,
            ]);

        $this->mailer->send($message);
    }

    private function sendAddedToChallengeNotification(string $email, string $challengeName): void
    {
        $loginUrl = $this->urlGenerator->generate(
            'loginMenu',
            ['challengeName' => $challengeName],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $message = (new TemplatedEmail())
            ->from(new Address('noreply@challengerator.local', 'Challengerator'))
            ->to($email)
            ->subject("You've been added to $challengeName")
            ->htmlTemplate('email/voter_added.html.twig')
            ->context([
                'challengeName' => $challengeName,
                'loginUrl'      => $loginUrl,
            ]);

        $this->mailer->send($message);
    }

    public function findValidVerification(string $token): ?EmailVerification
    {
        $verification = $this->verificationRepository->findByToken($token);

        if ($verification === null || $verification->isExpired() || !$verification->isJoinChallenge()) {
            return null;
        }

        return $verification;
    }

    public function acceptInvite(EmailVerification $verification, string $username, string $plainPassword): void
    {
        $inviteEmail = $verification->getEmail();

        $user = $this->userRepository->findByEmail($inviteEmail);

        if ($user !== null && $user->isVerified()) {
            // This token predates the current invite flow. Invalidate it and surface the anomaly.
            $this->verificationRepository->delete($verification);
            throw new BusinessLogicException('This invite link is no longer valid. Please ask the challenge admin to re-invite you.');
        }

        $existing = $this->userRepository->findByUsername($username);
        if ($existing !== null && ($user === null || $existing->getId() !== $user->getId())) {
            throw new BusinessLogicException('That username is already taken. Please choose another.');
        }

        if ($user === null) {
            $user = new User($username);
            $user->setEmail($inviteEmail);
        } else {
            $user->setUsername($username);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->verify();

        try {
            $this->transaction->transactional(function () use ($user, $verification): void {
                $this->userRepository->save($user);
                $this->userRepository->addToChallenge($user, $verification->getChallengeId() ?? '');
                $this->verificationRepository->delete($verification);
            });
        } catch (UniqueConstraintViolationException) {
            $this->verificationRepository->delete($verification);
            throw new BusinessLogicException('This invite link was already accepted. Please log in.');
        }
    }
}
