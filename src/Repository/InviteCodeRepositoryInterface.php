<?php
declare(strict_types=1);

namespace App\Repository;

interface InviteCodeRepositoryInterface
{
    public function validateAndConsume(string $code): bool;
}
