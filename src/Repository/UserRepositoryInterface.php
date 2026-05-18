<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Auth\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function findByUsername(string $username): ?User;

    public function save(User $user): void;

    public function isInChallenge(User $user, string $challengeName): bool;

    public function addToChallenge(User $user, string $challengeName): void;
}
