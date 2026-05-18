<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Exception\ConcurrentModificationException;
use App\Repository\TransactionInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineTransaction implements TransactionInterface
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    public function transactional(callable $fn): mixed
    {
        $em   = $this->registry->getManager();
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            $result = $fn();
            $em->flush();
            $conn->commit();
            return $result;
        } catch (\Throwable $e) {
            // UnitOfWork::commit() rolls back and closes the EM in its finally block when
            // an exception occurs during flush, so the connection may already be rolled back.
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->registry->resetManager();
            throw $e;
        }
    }

    public function transactionalWithRetry(callable $fn, int $maxAttempts = 3): mixed
    {
        $last = null;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $em   = $this->registry->getManager();
            $conn = $em->getConnection();
            $conn->beginTransaction();
            try {
                $result = $fn();
                $em->flush();
                $conn->commit();
                return $result;
            } catch (OptimisticLockException $e) {
                $conn->rollBack();
                $this->registry->resetManager();
                $last = $e;
            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                throw $e;
            }
        }

        throw new ConcurrentModificationException('Vote conflict after retries; please try again', 0, $last);
    }
}
