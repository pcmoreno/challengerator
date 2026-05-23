<?php
declare(strict_types=1);

namespace App\Services;

use App\Entity\Auth\EmailVerification;
use App\Entity\Auth\User;
use App\Exception\AccountExistsException;
use App\Exception\BusinessLogicException;
use App\Repository\ChallengeRepositoryInterface;
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
        private ChallengeRepositoryInterface $challengeRepository,
        private TransactionInterface $transaction,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function invite(string $inviteEmail, string $challengeName): void
    {
        $existingUser = $this->userRepository->findByEmail($inviteEmail);

        if ($existingUser !== null && $this->userRepository->isInChallenge($existingUser, $challengeName)) {
            throw new BusinessLogicException("$inviteEmail is already a member of $challengeName.");
        }

        foreach ($this->verificationRepository->findPendingByEmailAndChallenge($inviteEmail, $challengeName) as $old) {
            $this->verificationRepository->delete($old);
        }

        $token = bin2hex(random_bytes(32));
        $verifiedUser = ($existingUser !== null && $existingUser->isVerified()) ? $existingUser : null;
        $verification = EmailVerification::forJoinChallenge(
            $verifiedUser,
            $inviteEmail,
            $token,
            new \DateTimeImmutable('+48 hours'),
            $challengeName,
        );
        $this->verificationRepository->save($verification);

        $link = $this->urlGenerator->generate(
            'voter_accept_invite',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $challengeDisplayName = $this->challengeRepository->find($challengeName)->getDisplayName();

        $message = (new TemplatedEmail())
            ->from(new Address('noreply@challengerator.local', 'Challengerator'))
            ->to($inviteEmail)
            ->subject("You've been invited to join $challengeDisplayName")
            ->htmlTemplate('email/voter_invite.html.twig')
            ->context([
                'link'                 => $link,
                'challengeName'        => $challengeName,
                'challengeDisplayName' => $challengeDisplayName,
            ]);

        $this->mailer->send($message);
    }

    public function acceptVerifiedUserInvite(EmailVerification $verification): void
    {
        $user = $verification->getUser()
            ?? throw new BusinessLogicException('This is not a verified-user invite.');
        $challengeName = $verification->getChallengeId()
            ?? throw new BusinessLogicException('Verification is missing challenge information.');

        $this->transaction->transactional(function () use ($user, $verification, $challengeName): void {
            $this->userRepository->addToChallenge($user, $challengeName);
            $this->verificationRepository->delete($verification);
        });
    }

    public function requestSelfRegistration(string $email, string $username, string $challengeName): void
    {
        if (trim($email) === '' || trim($username) === '') {
            throw new BusinessLogicException('Email and username are required.');
        }

        $existingUser = $this->userRepository->findByEmail($email);

        if ($existingUser !== null && $existingUser->isVerified()) {
            if ($this->userRepository->isInChallenge($existingUser, $challengeName)) {
                throw new BusinessLogicException("$email is already a member of $challengeName.");
            }
            throw new AccountExistsException($challengeName);
        }

        if ($this->userRepository->findByUsername($username) !== null) {
            throw new BusinessLogicException('That username is already taken. Please choose another.');
        }

        foreach ($this->verificationRepository->findPendingByEmailChallengeAndType($email, $challengeName, EmailVerification::TYPE_SELF_REGISTRATION) as $old) {
            $this->verificationRepository->delete($old);
        }

        $token = bin2hex(random_bytes(32));
        $verification = EmailVerification::forSelfRegistration(
            $email,
            $token,
            new \DateTimeImmutable('+10 minutes'),
            $challengeName,
            $username,
        );
        $this->verificationRepository->save($verification);

        $confirmUrl = $this->urlGenerator->generate(
            'voter_confirm_registration',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $challengeDisplayName = $this->challengeRepository->find($challengeName)->getDisplayName();

        $message = (new TemplatedEmail())
            ->from(new Address('noreply@challengerator.local', 'Challengerator'))
            ->to($email)
            ->subject("Confirm your registration for $challengeDisplayName")
            ->htmlTemplate('email/self_registration_confirm.html.twig')
            ->context([
                'challengeName'        => $challengeName,
                'challengeDisplayName' => $challengeDisplayName,
                'confirmUrl'           => $confirmUrl,
            ]);

        $this->mailer->send($message);
    }

    public function findValidSelfRegistrationVerification(string $token): ?EmailVerification
    {
        $verification = $this->verificationRepository->findByToken($token);

        if ($verification === null || $verification->isExpired() || !$verification->isSelfRegistration()) {
            return null;
        }

        return $verification;
    }

    public function confirmSelfRegistration(EmailVerification $verification, string $plainPassword): string
    {
        $username      = $verification->getPendingUsername();
        $challengeName = $verification->getChallengeId()
            ?? throw new BusinessLogicException('Verification is missing challenge information.');

        if ($this->userRepository->findByUsername($username) !== null) {
            $this->verificationRepository->delete($verification);
            throw new BusinessLogicException('The username you chose is no longer available. Please register again.');
        }

        $user = new User($username);
        $user->setEmail($verification->getEmail());
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->verify();

        $this->transaction->transactional(function () use ($user, $verification, $challengeName): void {
            $this->userRepository->save($user);
            $this->userRepository->addToChallenge($user, $challengeName);
            $this->verificationRepository->delete($verification);
        });

        return $challengeName;
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
