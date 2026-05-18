<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Doctrine\DbCar;
use App\Entity\Doctrine\DbChallenge;
use App\Exception\ConcurrentModificationException;
use App\Repository\Doctrine\DoctrineTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DoctrineTransactionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DoctrineTransaction $transaction;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em          = self::getContainer()->get(EntityManagerInterface::class);
        $this->transaction = new DoctrineTransaction($this->em);

        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $this->em->getConnection()->executeStatement('DELETE FROM car WHERE id = :id', ['id' => '__tx_test_car__']);
        $this->em->getConnection()->executeStatement('DELETE FROM challenge WHERE name = :n', ['n' => '__tx_test__']);
        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $this->em->getConnection()->executeStatement('DELETE FROM car WHERE id = :id', ['id' => '__tx_test_car__']);
        $this->em->getConnection()->executeStatement('DELETE FROM challenge WHERE name = :n', ['n' => '__tx_test__']);
        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    public function test_retries_on_version_conflict_and_em_stays_open(): void
    {
        $challenge = new DbChallenge('__tx_test__', 'secret');
        $this->em->persist($challenge);
        $this->em->flush();

        $car = new DbCar('__tx_test_car__', $challenge, '__tx_test__', 'a', 'b');
        $this->em->persist($car);
        $this->em->flush();
        $this->em->clear();

        $attempts = 0;
        $em       = $this->em; // captured once, like a repository — the fix must work with this

        $this->transaction->transactionalWithRetry(function () use ($em, &$attempts): void {
            $attempts++;
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

        $refreshed = $this->em->find(DbCar::class, '__tx_test_car__');
        $this->assertNotNull($refreshed);
        $this->assertSame(1600, $refreshed->getRating());
    }

    public function test_throws_ConcurrentModificationException_after_exhausting_retries(): void
    {
        $challenge = new DbChallenge('__tx_test__', 'secret');
        $this->em->persist($challenge);
        $this->em->flush();

        $car = new DbCar('__tx_test_car__', $challenge, '__tx_test__', 'a', 'b');
        $this->em->persist($car);
        $this->em->flush();
        $this->em->clear();

        $em = $this->em;

        $this->expectException(ConcurrentModificationException::class);

        $this->transaction->transactionalWithRetry(function () use ($em): void {
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
