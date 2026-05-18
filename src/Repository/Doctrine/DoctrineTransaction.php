<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Repository\TransactionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

class DoctrineTransaction implements TransactionInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function transactional(callable $fn): mixed
    {
        return $this->em->wrapInTransaction($fn);
    }

    public function transactionalWithRetry(callable $fn, int $maxAttempts = 3): mixed
    {
        $last = null;
        $conn = $this->em->getConnection();

        for ($i = 0; $i < $maxAttempts; $i++) {
            $conn->beginTransaction();
            try {
                $result = $fn();
                $this->em->flush();
                $conn->commit();
                return $result;
            } catch (OptimisticLockException $e) {
                $conn->rollBack();
                $this->em->clear();
                $last = $e;
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }
        }

        throw $last;
    }
}
