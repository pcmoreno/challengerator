<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Challenge\Rating;
use App\Entity\Doctrine\DbCar;
use App\Entity\Doctrine\DbChallenge;
use App\Repository\CarRepositoryInterface;
use App\Repository\TransactionInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RepoTransactionRetryTest extends KernelTestCase
{
    private ManagerRegistry $registry;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->registry = self::getContainer()->get(ManagerRegistry::class);

        $conn = $this->registry->getManager()->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('DELETE FROM car WHERE id = :id', ['id' => '__retry_test_car__']);
        $conn->executeStatement('DELETE FROM challenge WHERE name = :n', ['n' => '__retry_test__']);
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        $conn = $this->registry->getManager()->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('DELETE FROM car WHERE id = :id', ['id' => '__retry_test_car__']);
        $conn->executeStatement('DELETE FROM challenge WHERE name = :n', ['n' => '__retry_test__']);
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    public function test_repo_retry_succeeds_via_production_wiring(): void
    {
        $em = $this->registry->getManager();

        $challenge = new DbChallenge('__retry_test__', 'secret');
        $em->persist($challenge);
        $em->flush();

        $car = new DbCar('__retry_test_car__', $challenge, '__retry_test__', 'a', 'b');
        $em->persist($car);
        $em->flush();
        $em->clear();

        // Resolve the production-wired services from the container
        $carRepo     = self::getContainer()->get(CarRepositoryInterface::class);
        $transaction = self::getContainer()->get(TransactionInterface::class);

        $attempts = 0;
        $registry  = $this->registry;

        $transaction->transactionalWithRetry(function () use ($carRepo, $registry, &$attempts): void {
            $attempts++;
            $car = $carRepo->find('__retry_test_car__');

            if ($attempts === 1) {
                // Simulate a concurrent write bumping the version before our flush
                $registry->getManager()->getConnection()->executeStatement(
                    'UPDATE car SET version = version + 1 WHERE id = :id',
                    ['id' => '__retry_test_car__']
                );
            }

            $car->setRating(Rating::fromInt(1700));
            // save() is called inside the closure so DoctrineCarRepository::save() + flush()
            // is what triggers the OptimisticLockException on attempt 1
            $carRepo->save($car);
        });

        $this->assertSame(2, $attempts, 'Should have retried once after the version conflict');

        $refreshed = $carRepo->find('__retry_test_car__');
        $this->assertSame(1700, $refreshed->getRating()->getRating());
    }
}
