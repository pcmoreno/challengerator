<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Doctrine\DbStorageResource;
use App\Entity\StorageType;

interface StorageResourceRepositoryInterface
{
    public function findForChallenge(int $challengeId, StorageType $type): ?DbStorageResource;
    public function save(DbStorageResource $resource): void;
    public function delete(DbStorageResource $resource): void;
}
