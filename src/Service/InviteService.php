<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Auth\EmailVerification;
use App\Entity\Auth\User;
use App\Entity\Doctrine\DbChallenge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class InviteService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function invite(string $inviteEmail, string $challengeName): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $inviteEmail]);
        if ($user !== null) {
            $dbChallenge = $this->em->getRepository(DbChallenge::class)->findOneBy(['name' => $challengeName]);
            $alreadyInChallenge = $dbChallenge !== null && $dbChallenge->getVoters()->contains($user);
            if ($alreadyInChallenge) {
                throw new \DomainException("$inviteEmail is already a member of $challengeName.");
            }
        } else {
            $user = new User('invite_' . bin2hex(random_bytes(8)));
            $user->setEmail($inviteEmail);
            $this->em->persist($user);
        }

        $token = bin2hex(random_bytes(32));
        $verification = new EmailVerification(
            $user,
            $token,
            new \DateTimeImmutable('+48 hours'),
            EmailVerification::TYPE_JOIN_CHALLENGE,
            $challengeName,
        );
        $this->em->persist($verification);
        $this->em->flush();

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

    public function findValidVerification(string $token): ?EmailVerification
    {
        $verification = $this->em->getRepository(EmailVerification::class)
            ->findOneBy(['token' => $token]);

        if ($verification === null || $verification->isExpired() || !$verification->isJoinChallenge()) {
            return null;
        }

        return $verification;
    }

    public function acceptInvite(EmailVerification $verification, string $username, string $plainPassword): void
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        if ($existing !== null && $existing->getId() !== $verification->getUser()->getId()) {
            throw new \DomainException('That username is already taken. Please choose another.');
        }

        $user = $verification->getUser();
        $user->setUsername($username);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->verify();

        $challengeName = $verification->getChallengeId();
        $dbChallenge = $this->em->getRepository(DbChallenge::class)->findOneBy(['name' => $challengeName]);
        if ($dbChallenge !== null) {
            $dbChallenge->addVoter($user);
        }

        $this->em->remove($verification);
        $this->em->flush();
    }

}
