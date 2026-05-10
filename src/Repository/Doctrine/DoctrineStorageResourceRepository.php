<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Entity\Doctrine\DbStorageResource;
use App\Entity\StorageType;
use App\Repository\StorageResourceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineStorageResourceRepository implements StorageResourceRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function findForChallenge(int $challengeId, StorageType $type): ?DbStorageResource
    {
        return $this->em->getRepository(DbStorageResource::class)
            ->findOneBy(['challenge' => $challengeId, 'type' => $type]);
    }

    public function save(DbStorageResource $resource): void
    {
        $this->em->persist($resource);
        $this->em->flush();
    }

    public function delete(DbStorageResource $resource): void
    {
        $this->em->remove($resource);
        $this->em->flush();
    }
}
