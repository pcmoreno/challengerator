<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Entity\Auth\User;
use App\Entity\Challenge\RoundOfComparisons;
use App\Entity\Challenge\Voter;
use App\Entity\Doctrine\DbCar;
use App\Entity\Doctrine\DbChallenge;
use App\Entity\Doctrine\DbVoterCarQueue;
use App\Repository\VoterRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineVoterRepository implements VoterRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function find(string $id): ?Voter
    {
        $user = $this->em->find(User::class, (int)$id);
        return $user ? $this->toDomain($user) : null;
    }

    public function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $users = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->andWhere("u.roles NOT LIKE :superAdmin")
            ->setParameter('ids', array_map('intval', $ids))
            ->setParameter('superAdmin', '%ROLE_SUPER_ADMIN%')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($users as $user) {
            $indexed[(string)$user->getId()] = $this->toDomain($user);
        }

        return array_values(array_filter(array_map(fn($id) => $indexed[$id] ?? null, $ids)));
    }

    public function findByName(string $name): ?Voter
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $name]);
        return $user ? $this->toDomain($user) : null;
    }

    public function hasVoterFromIpForChallenge(string $ip, string $challengeName): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(DbChallenge::class, 'c')
            ->join('c.voters', 'u')
            ->where('u.ipAddress = :ip')
            ->andWhere('c.name = :challengeName')
            ->setParameter('ip', $ip)
            ->setParameter('challengeName', $challengeName)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function save(Voter $voter): void
    {
        $rawId = $voter->getId();
        $user  = ctype_digit($rawId) ? $this->em->find(User::class, (int)$rawId) : null;

        if ($user === null) {
            $user = $this->em->getRepository(User::class)->findOneBy(['username' => $voter->getName()]);
            if ($user !== null && in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                throw new \DomainException('Cannot add a super admin as a voter.');
            }
        }

        if ($user === null) {
            $user = new User($voter->getName());
        }

        $user->setPassword($voter->getAuthKey());
        $user->setIpAddress($voter->getIpAddress());
        $this->em->persist($user);
        $this->em->flush();

        $this->syncQueue($user, $voter);
        $this->em->flush();
    }

    public function delete(string $id): void
    {
        $user = $this->em->find(User::class, (int)$id);
        if ($user !== null) {
            $this->em->remove($user);
            $this->em->flush();
        }
    }

    private function toDomain(User $user): Voter
    {
        $queueRows = $this->em->getRepository(DbVoterCarQueue::class)->findBy(['user' => $user->getId()]);

        $grouped = [];
        foreach ($queueRows as $row) {
            $challengeName = $row->getChallenge()->getName();
            if (!isset($grouped[$challengeName])) {
                $grouped[$challengeName] = ['pending' => [], 'voted' => []];
            }
            if ($row->isPending()) {
                $grouped[$challengeName]['pending'][] = $row->getCar()->getId();
            } else {
                $grouped[$challengeName]['voted'][] = $row->getCar()->getId();
            }
        }

        $rounds = new RoundOfComparisons();
        foreach ($grouped as $challengeName => $queues) {
            $rounds->setCarsToBeVotedForChallenge($queues['pending'], $challengeName);
            $rounds->setCarsAlreadyComparedForChallenge($queues['voted'], $challengeName);
        }

        // registered challenges with no queue entries still need to be present
        $registeredChallenges = $this->em->createQueryBuilder()
            ->select('c')
            ->from(DbChallenge::class, 'c')
            ->join('c.voters', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->getResult();
        foreach ($registeredChallenges as $dbChallenge) {
            if (!$rounds->has($dbChallenge->getName())) {
                $rounds->setCarsToBeVotedForChallenge([], $dbChallenge->getName());
                $rounds->setCarsAlreadyComparedForChallenge([], $dbChallenge->getName());
            }
        }

        return Voter::fromDoctrineData([
            'id'       => (string)$user->getId(),
            'name'     => $user->getUsername(),
            'authKey'  => $user->getPassword() ?? '',
            'ipAddress' => $user->getIpAddress(),
            'rounds'   => $rounds,
        ]);
    }

    private function syncQueue(User $user, Voter $voter): void
    {
        $existing = $this->em->getRepository(DbVoterCarQueue::class)->findBy(['user' => $user->getId()]);
        foreach ($existing as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();

        $rounds = $voter->getChallengeRounds();

        // Collect all car IDs and challenge names up-front for batch fetching
        $allCarIds = [];
        $challengeNames = array_keys($rounds);
        foreach ($rounds as $queues) {
            $allCarIds = array_merge($allCarIds, $queues['carsToVote'] ?? [], $queues['carsCompared'] ?? []);
        }
        $allCarIds = array_unique($allCarIds);

        if (empty($challengeNames) || empty($allCarIds)) {
            return;
        }

        $carMap = [];
        foreach ($this->em->getRepository(DbCar::class)->findBy(['id' => $allCarIds]) as $dbCar) {
            $carMap[$dbCar->getId()] = $dbCar;
        }

        $challengeMap = [];
        foreach ($this->em->getRepository(DbChallenge::class)->findBy(['name' => $challengeNames]) as $dbChallenge) {
            $challengeMap[$dbChallenge->getName()] = $dbChallenge;
        }

        foreach ($rounds as $challengeName => $queues) {
            $dbChallenge = $challengeMap[$challengeName] ?? null;
            if ($dbChallenge === null) {
                continue;
            }

            foreach ($queues['carsToVote'] ?? [] as $carId) {
                $dbCar = $carMap[$carId] ?? null;
                if ($dbCar !== null) {
                    $this->em->persist(new DbVoterCarQueue($user, $dbChallenge, $dbCar));
                }
            }

            foreach ($queues['carsCompared'] ?? [] as $carId) {
                $dbCar = $carMap[$carId] ?? null;
                if ($dbCar !== null) {
                    $row = new DbVoterCarQueue($user, $dbChallenge, $dbCar);
                    $row->markVoted();
                    $this->em->persist($row);
                }
            }
        }
    }
}
