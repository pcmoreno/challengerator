<?php
declare(strict_types=1);

namespace App\Tests\Repository\InMemory;

use App\Repository\TransactionInterface;
use Doctrine\ORM\OptimisticLockException;

class FlakyTransaction implements TransactionInterface
{
    private int $failsRemaining;

    public function __construct(int $failCount)
    {
        $this->failsRemaining = $failCount;
    }

    public function transactional(callable $fn): mixed
    {
        return $fn();
    }

    public function transactionalWithRetry(callable $fn, int $maxAttempts = 3): mixed
    {
        if ($this->failsRemaining > 0) {
            $this->failsRemaining--;
            throw OptimisticLockException::lockFailed(new \stdClass());
        }
        return $fn();
    }
}
