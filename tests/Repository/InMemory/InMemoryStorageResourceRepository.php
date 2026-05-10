<?php
declare(strict_types=1);

namespace App\Tests\Repository\InMemory;

use App\Entity\Doctrine\DbStorageResource;
use App\Entity\StorageType;
use App\Repository\StorageResourceRepositoryInterface;

class InMemoryStorageResourceRepository implements StorageResourceRepositoryInterface
{
    /** @var array<string, DbStorageResource> */
    private array $store = [];

    public function findForChallenge(int $challengeId, StorageType $type): ?DbStorageResource
    {
        return $this->store["{$challengeId}:{$type->value}"] ?? null;
    }

    public function save(DbStorageResource $resource): void
    {
        $key = "{$resource->getChallenge()->getId()}:{$resource->getType()->value}";
        $this->store[$key] = $resource;
    }

    public function delete(DbStorageResource $resource): void
    {
        $key = "{$resource->getChallenge()->getId()}:{$resource->getType()->value}";
        unset($this->store[$key]);
    }
}
