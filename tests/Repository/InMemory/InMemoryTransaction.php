<?php
declare(strict_types=1);

namespace App\Tests\Repository\InMemory;

use App\Repository\TransactionInterface;

class InMemoryTransaction implements TransactionInterface
{
    public function transactional(callable $fn): mixed
    {
        return $fn();
    }
}
