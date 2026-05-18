<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Entity\Doctrine\DbStorageResource;
use App\Entity\StorageType;
use App\Repository\StorageResourceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineStorageResourceRepository implements StorageResourceRepositoryInterface
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    private function em(): EntityManagerInterface
    {
        return $this->registry->getManager();
    }

    public function findForChallenge(int $challengeId, StorageType $type): ?DbStorageResource
    {
        return $this->em()->getRepository(DbStorageResource::class)
            ->findOneBy(['challenge' => $challengeId, 'type' => $type]);
    }

    public function save(DbStorageResource $resource): void
    {
        $this->em()->persist($resource);
        $this->em()->flush();
    }

    public function delete(DbStorageResource $resource): void
    {
        $this->em()->remove($resource);
        $this->em()->flush();
    }
}
