<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Challenge\Voter;

interface VoterRepositoryInterface
{
    public function find(string $id): ?Voter;
    public function findMany(array $ids): array;
    public function findByName(string $name): ?Voter;
    public function hasVoterFromIpForChallenge(string $ip, string $challengeName): bool;
    public function save(Voter $voter): void;
    public function delete(string $id): void;
    public function markCarsVoted(string $voterId, string $challengeName, array $carIds): void;
}
