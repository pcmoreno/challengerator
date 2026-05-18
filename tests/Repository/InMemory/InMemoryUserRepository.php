<?php
declare(strict_types=1);

namespace App\Tests\Repository\InMemory;

use App\Entity\Auth\User;
use App\Repository\UserRepositoryInterface;

class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var User[] */
    private array $users = [];

    /** @var array<string, string[]> challengeName => userId[] */
    private array $members = [];

    private int $nextId = 1;

    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getEmail() === $email) {
                return $user;
            }
        }
        return null;
    }

    public function findByUsername(string $username): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getUsername() === $username) {
                return $user;
            }
        }
        return null;
    }

    public function save(User $user): void
    {
        if (!isset($this->users[spl_object_id($user)])) {
            // Assign a synthetic int id via reflection so getId() works in tests
            $ref = new \ReflectionProperty(User::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($user, $this->nextId++);
        }
        $this->users[spl_object_id($user)] = $user;
    }

    public function isInChallenge(User $user, string $challengeName): bool
    {
        return in_array((string) $user->getId(), $this->members[$challengeName] ?? [], true);
    }

    public function addToChallenge(User $user, string $challengeName): void
    {
        $this->members[$challengeName][] = (string) $user->getId();
    }
}
