<?php
declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\Challenge\Car;
use App\Repository\CarRepositoryInterface;

class InMemoryCarRepository implements CarRepositoryInterface
{
    /** @var Car[] */
    private array $cars = [];

    public function find(string $id): Car
    {
        if (!isset($this->cars[$id])) {
            throw new \RuntimeException("Car '$id' not found");
        }
        return $this->cars[$id];
    }

    public function findMany(array $ids): array
    {
        return array_map(fn($id) => $this->find($id), $ids);
    }

    public function save(Car $car): void
    {
        $this->cars[$car->getId()] = $car;
    }

    public function delete(string $id): void
    {
        unset($this->cars[$id]);
    }
}
