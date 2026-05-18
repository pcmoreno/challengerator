<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Entity\Auth\User;
use App\Entity\Doctrine\DbChallenge;
use App\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    private function em(): EntityManagerInterface
    {
        return $this->registry->getManager();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function findByUsername(string $username): ?User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['username' => $username]);
    }

    public function save(User $user): void
    {
        $this->em()->persist($user);
        $this->em()->flush();
    }

    public function isInChallenge(User $user, string $challengeName): bool
    {
        $count = (int) $this->em()->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(DbChallenge::class, 'c')
            ->join('c.voters', 'u')
            ->where('u.id = :userId')
            ->andWhere('c.name = :challengeName')
            ->setParameter('userId', $user->getId())
            ->setParameter('challengeName', $challengeName)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function addToChallenge(User $user, string $challengeName): void
    {
        $dbChallenge = $this->em()->getRepository(DbChallenge::class)->findOneBy(['name' => $challengeName]);
        if ($dbChallenge === null) {
            return;
        }
        $dbChallenge->addVoter($user);
        $this->em()->flush();
    }
}
