<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Entity\Auth\User;
use App\Entity\Challenge\Challenge;
use App\Entity\Doctrine\DbChallenge;
use App\Repository\ChallengeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineChallengeRepository implements ChallengeRepositoryInterface
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    private function em(): EntityManagerInterface
    {
        return $this->registry->getManager();
    }

    public function create(string $name): void
    {
        // No-op: the challenge row is created on first save()
    }

    public function find(string $name): Challenge
    {
        $dbChallenge = $this->em()->getRepository(DbChallenge::class)->findOneBy(['name' => $name]);
        if ($dbChallenge === null) {
            throw new \Exception("Challenge not found: $name");
        }
        return $this->toDomain($dbChallenge);
    }

    public function save(Challenge $challenge): void
    {
        $dbChallenge = $this->em()->getRepository(DbChallenge::class)
            ->findOneBy(['name' => $challenge->getName()]);

        if ($dbChallenge === null) {
            $dbChallenge = new DbChallenge($challenge->getName(), $challenge->getOwner(), $challenge->getDisplayName());
        } else {
            $dbChallenge->setAdminPassword($challenge->getOwner());
            $dbChallenge->setDisplayName($challenge->getDisplayName());
        }

        $dbChallenge->setIsActive($challenge->isActive());
        $dbChallenge->setAllowSelfRegistration($challenge->allowsSelfRegistration());
        $dbChallenge->setSelfRegistrationCode($challenge->getSelfRegistrationCode());

        $this->syncVoters($dbChallenge, $challenge->getVoters());

        $this->em()->persist($dbChallenge);
        $this->em()->flush();
    }

    public function listNames(): array
    {
        return $this->em()->getRepository(DbChallenge::class)
            ->createQueryBuilder('c')
            ->select('c.name', 'c.displayName')
            ->getQuery()
            ->getArrayResult();
    }

    public function listNamesForUser(User $user): array
    {
        return $this->em()->getRepository(DbChallenge::class)
            ->createQueryBuilder('c')
            ->select('c.name', 'c.displayName')
            ->join('c.voters', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->getArrayResult();
    }

    private function toDomain(DbChallenge $dbChallenge): Challenge
    {
        $carIds   = array_map(fn($c) => $c->getId(), $dbChallenge->getCars()->toArray());
        $voterIds = array_map(fn($u) => (string)$u->getId(), $dbChallenge->getVoters()->toArray());

        return Challenge::fromArray([
            'id'                    => $dbChallenge->getName(),
            'name'                  => $dbChallenge->getName(),
            'displayName'           => $dbChallenge->getDisplayName(),
            'cars'                  => $carIds,
            'voters'                => $voterIds,
            'isActive'              => $dbChallenge->isActive(),
            'owner'                 => $dbChallenge->getAdminPassword(),
            'allowSelfRegistration' => $dbChallenge->isAllowSelfRegistration(),
            'selfRegistrationCode'  => $dbChallenge->getSelfRegistrationCode(),
        ]);
    }

    private function syncVoters(DbChallenge $dbChallenge, array $voterIds): void
    {
        $voterIds    = array_map('intval', $voterIds);
        $currentIds  = array_map(fn($u) => $u->getId(), $dbChallenge->getVoters()->toArray());

        $toAdd    = array_diff($voterIds, $currentIds);
        $toRemove = array_diff($currentIds, $voterIds);

        if ($toAdd) {
            $newUsers = $this->em()->getRepository(User::class)->findBy(['id' => $toAdd]);
            foreach ($newUsers as $user) {
                $dbChallenge->addVoter($user);
            }
        }

        if ($toRemove) {
            foreach ($dbChallenge->getVoters() as $user) {
                if (in_array($user->getId(), $toRemove)) {
                    $dbChallenge->removeVoter($user);
                }
            }
        }
    }
}
