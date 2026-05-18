<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Entity\Challenge\Car;
use App\Entity\Challenge\Rating;
use App\Entity\Doctrine\DbCar;
use App\Entity\Doctrine\DbChallenge;
use App\Repository\CarRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineCarRepository implements CarRepositoryInterface
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    private function em(): EntityManagerInterface
    {
        return $this->registry->getManager();
    }

    public function find(string $id): Car
    {
        $dbCar = $this->em()->find(DbCar::class, $id);
        if ($dbCar === null) {
            throw new \Exception("Car not found: $id");
        }
        return $this->toDomain($dbCar);
    }

    public function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $dbCars = $this->em()->getRepository(DbCar::class)
            ->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($dbCars as $dbCar) {
            $indexed[$dbCar->getId()] = $this->toDomain($dbCar);
        }

        // preserve input order
        return array_values(array_filter(array_map(fn($id) => $indexed[$id] ?? null, $ids)));
    }

    public function save(Car $car): void
    {
        $dbCar = $this->em()->find(DbCar::class, $car->getId());

        if ($dbCar === null) {
            $dbChallenge = $this->em()->getRepository(DbChallenge::class)
                ->findOneBy(['name' => $car->getChallengeId()]);

            if ($dbChallenge === null) {
                throw new \Exception("Challenge not found: {$car->getChallengeId()}");
            }

            $dbCar = new DbCar($car->getId(), $dbChallenge, $car->getName(), $car->getImageUrlA(), $car->getImageUrlB());
        }

        $dbCar->setRating($car->getRating()->getRating());
        $this->em()->persist($dbCar);
        $this->em()->flush();
    }

    public function delete(string $id): void
    {
        $dbCar = $this->em()->find(DbCar::class, $id);
        if ($dbCar !== null) {
            $this->em()->remove($dbCar);
            $this->em()->flush();
        }
    }

    private function toDomain(DbCar $dbCar): Car
    {
        $car = Car::create([
            '_id'         => $dbCar->getId(),
            'name'        => $dbCar->getName(),
            'imageUrlA'   => $dbCar->getImageUrlA(),
            'imageUrlB'   => $dbCar->getImageUrlB(),
            'challengeId' => $dbCar->getChallenge()->getName(),
        ]);
        $car->setRating(Rating::fromInt($dbCar->getRating()));
        return $car;
    }
}
