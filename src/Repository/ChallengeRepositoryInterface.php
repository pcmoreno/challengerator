<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Challenge\Challenge;

interface ChallengeRepositoryInterface
{
    public function create(string $name): void;
    public function find(string $name): Challenge;
    public function save(Challenge $challenge): void;
    public function listNames(): array;
}
