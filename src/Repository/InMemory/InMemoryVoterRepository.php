<?php
declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\Challenge\Voter;
use App\Repository\VoterRepositoryInterface;

class InMemoryVoterRepository implements VoterRepositoryInterface
{
    /** @var Voter[] */
    private array $voters = [];

    public function find(string $id): ?Voter
    {
        return $this->voters[$id] ?? null;
    }

    public function findMany(array $ids): array
    {
        return array_values(array_filter(array_map(fn($id) => $this->voters[$id] ?? null, $ids)));
    }

    public function findByName(string $name): ?Voter
    {
        foreach ($this->voters as $voter) {
            if ($voter->getName() === $name) {
                return $voter;
            }
        }
        return null;
    }

    public function hasVoterFromIpForChallenge(string $ip, string $challengeName): bool
    {
        foreach ($this->voters as $voter) {
            if ($voter->getIpAddress() === $ip && $voter->isRegisteredForChallenge($challengeName)) {
                return true;
            }
        }
        return false;
    }

    public function save(Voter $voter): void
    {
        $this->voters[$voter->getId()] = $voter;
    }

    public function delete(string $id): void
    {
        unset($this->voters[$id]);
    }
}
