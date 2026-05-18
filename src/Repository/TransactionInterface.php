<?php
declare(strict_types=1);

namespace App\Repository;

interface TransactionInterface
{
    /** @template T @param callable(): T $fn @return T */
    public function transactional(callable $fn): mixed;
}
