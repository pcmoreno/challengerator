<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Doctrine\DbCar;
use App\Entity\Doctrine\DbChallenge;
use App\Exception\ConcurrentModificationException;
use App\Repository\Doctrine\DoctrineTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DoctrineTransactionTest extends KernelTestCase
{
    private ManagerRegistry $registry;
    private DoctrineTransaction $transaction;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->registry    = self::getContainer()->get(ManagerRegistry::class);
        $this->transaction = new DoctrineTransaction($this->registry);

        $em = $this->registry->getManager();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $em->getConnection()->executeStatement('DELETE FROM car WHERE id = :id', ['id' => '__tx_test_car__']);
        $em->getConnection()->executeStatement('DELETE FROM challenge WHERE name = :n', ['n' => '__tx_test__']);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        $em = $this->registry->getManager();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $em->getConnection()->executeStatement('DELETE FROM car WHERE id = :id', ['id' => '__tx_test_car__']);
        $em->getConnection()->executeStatement('DELETE FROM challenge WHERE name = :n', ['n' => '__tx_test__']);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    public function test_retries_on_version_conflict_and_em_stays_open(): void
    {
        $em = $this->registry->getManager();
        $challenge = new DbChallenge('__tx_test__', 'secret');
        $em->persist($challenge);
        $em->flush();

        $car = new DbCar('__tx_test_car__', $challenge, '__tx_test__', 'a', 'b');
        $em->persist($car);
        $em->flush();
        $em->clear();

        $attempts = 0;
        $registry  = $this->registry;

        $this->transaction->transactionalWithRetry(function () use (&$attempts, $registry): void {
            $attempts++;
            // Always fetch the EM fresh from the registry so we use the post-reset instance
            $em    = $registry->getManager();
            $dbCar = $em->find(DbCar::class, '__tx_test_car__');

            if ($attempts === 1) {
                // Simulate a concurrent write bumping the version before our flush
                $em->getConnection()->executeStatement(
                    'UPDATE car SET version = version + 1 WHERE id = :id',
                    ['id' => '__tx_test_car__']
                );
            }

            $dbCar->setRating(1600);
        });

        $this->assertSame(2, $attempts, 'Should have retried exactly once after the version conflict');

        $refreshed = $this->registry->getManager()->find(DbCar::class, '__tx_test_car__');
        $this->assertNotNull($refreshed);
        $this->assertSame(1600, $refreshed->getRating());
    }

    public function test_throws_ConcurrentModificationException_after_exhausting_retries(): void
    {
        $em = $this->registry->getManager();
        $challenge = new DbChallenge('__tx_test__', 'secret');
        $em->persist($challenge);
        $em->flush();

        $car = new DbCar('__tx_test_car__', $challenge, '__tx_test__', 'a', 'b');
        $em->persist($car);
        $em->flush();
        $em->clear();

        $registry = $this->registry;

        $this->expectException(ConcurrentModificationException::class);

        $this->transaction->transactionalWithRetry(function () use ($registry): void {
            $em    = $registry->getManager();
            $dbCar = $em->find(DbCar::class, '__tx_test_car__');
            // Always bump version to force perpetual conflict
            $em->getConnection()->executeStatement(
                'UPDATE car SET version = version + 1 WHERE id = :id',
                ['id' => '__tx_test_car__']
            );
            $dbCar->setRating(1600);
        }, maxAttempts: 3);
    }
}
