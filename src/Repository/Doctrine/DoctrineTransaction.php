<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Repository\TransactionInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineTransaction implements TransactionInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function transactional(callable $fn): mixed
    {
        return $this->em->wrapInTransaction($fn);
    }
}
