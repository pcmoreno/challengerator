<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Auth\User;
use App\Entity\Doctrine\DbCar;
use App\Entity\Doctrine\DbChallenge;
use App\Exception\ConcurrentModificationException;
use App\Repository\Doctrine\DoctrineTransaction;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

        $conn = $this->registry->getManager()->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('DELETE FROM car WHERE id = :id', ['id' => '__tx_test_car__']);
        $conn->executeStatement('DELETE FROM challenge WHERE name = :n', ['n' => '__tx_test__']);
        $conn->executeStatement('DELETE FROM `user` WHERE username LIKE :u', ['u' => '__tx_unique_test%']);
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        $conn = $this->registry->getManager()->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('DELETE FROM car WHERE id = :id', ['id' => '__tx_test_car__']);
        $conn->executeStatement('DELETE FROM challenge WHERE name = :n', ['n' => '__tx_test__']);
        $conn->executeStatement('DELETE FROM `user` WHERE username LIKE :u', ['u' => '__tx_unique_test%']);
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
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

        $this->transaction->transactionalWithRetry(function () use ($registry, &$attempts): void {
            $em = $registry->getManager(); // always the current (possibly fresh) EM
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

    public function test_transactional_em_stays_open_after_unique_constraint_violation(): void
    {
        $em = $this->registry->getManager();

        $user1 = new User('__tx_unique_test_1__');
        $user1->setEmail('__tx_unique_test@example.com');
        $em->persist($user1);
        $em->flush();
        $em->clear();

        // Attempt to insert a second user with the same email inside a transaction
        try {
            $this->transaction->transactional(function () use ($em): void {
                $user2 = new User('__tx_unique_test_2__');
                $user2->setEmail('__tx_unique_test@example.com');
                $em->persist($user2);
            });
            $this->fail('Expected UniqueConstraintViolationException');
        } catch (UniqueConstraintViolationException) {
            // expected
        }

        // EM must be usable after the transaction — resetManager() ensures a fresh one
        $found = $this->registry->getManager()
            ->getRepository(User::class)
            ->findOneBy(['username' => '__tx_unique_test_1__']);

        $this->assertNotNull($found, 'EM must still be usable after UniqueConstraintViolationException inside transactional()');
    }
}
