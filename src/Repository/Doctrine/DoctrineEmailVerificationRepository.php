<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Entity\Auth\EmailVerification;
use App\Repository\EmailVerificationRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineEmailVerificationRepository implements EmailVerificationRepositoryInterface
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    private function em(): EntityManagerInterface
    {
        return $this->registry->getManager();
    }

    public function findByToken(string $token): ?EmailVerification
    {
        return $this->em()->getRepository(EmailVerification::class)->findOneBy(['token' => $token]);
    }

    public function findPendingByEmailAndChallenge(string $email, string $challengeName): array
    {
        return $this->em()->getRepository(EmailVerification::class)->findBy([
            'email'       => $email,
            'challengeId' => $challengeName,
            'type'        => EmailVerification::TYPE_JOIN_CHALLENGE,
        ]);
    }

    public function save(EmailVerification $verification): void
    {
        $this->em()->persist($verification);
        $this->em()->flush();
    }

    public function delete(EmailVerification $verification): void
    {
        $em = $this->em();
        // After a manager reset (e.g. following a rolled-back transaction) the entity
        // may be detached from the fresh EM — refetch it before removing.
        $managed = $em->contains($verification)
            ? $verification
            : $em->find(EmailVerification::class, $verification->getId());
        if ($managed === null) {
            return;
        }
        $em->remove($managed);
        $em->flush();
    }
}
