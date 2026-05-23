<?php
declare(strict_types=1);

namespace App\Tests\Repository\InMemory;

use App\Entity\Challenge\Challenge;
use App\Repository\ChallengeRepositoryInterface;

class InMemoryChallengeRepository implements ChallengeRepositoryInterface
{
    /** @var Challenge[] */
    private array $challenges = [];

    public function create(string $name): void
    {
        // no-op: storage is implicit on first save
    }

    public function find(string $name): Challenge
    {
        if (!isset($this->challenges[$name])) {
            throw new \RuntimeException("Challenge '$name' not found");
        }
        return $this->challenges[$name];
    }

    public function save(Challenge $challenge): void
    {
        $this->challenges[$challenge->getName()] = $challenge;
    }

    public function listNames(): array
    {
        return array_map(
            fn(Challenge $c) => ['name' => $c->getName(), 'displayName' => $c->getDisplayName()],
            array_values($this->challenges)
        );
    }
}
