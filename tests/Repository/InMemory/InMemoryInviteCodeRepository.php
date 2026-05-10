<?php
declare(strict_types=1);

namespace App\Tests\Repository\InMemory;

use App\Repository\InviteCodeRepositoryInterface;

class InMemoryInviteCodeRepository implements InviteCodeRepositoryInterface
{
    private array $codes;

    public function __construct(array $codes = [])
    {
        $this->codes = $codes;
    }

    public function validateAndConsume(string $code): bool
    {
        if (!in_array($code, $this->codes)) {
            return false;
        }
        $this->codes = array_values(array_diff($this->codes, [$code]));
        return true;
    }
}
