<?php
declare(strict_types=1);

namespace App\Tests\Repository\InMemory;

use App\Exception\ConcurrentModificationException;
use App\Repository\TransactionInterface;

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
            if ($this->failsRemaining > 0) {
                throw new ConcurrentModificationException('simulated conflict');
            }
        }
        return $fn();
    }
}
