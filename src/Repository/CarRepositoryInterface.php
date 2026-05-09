<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Challenge\Car;

interface CarRepositoryInterface
{
    public function find(string $id): Car;
    public function findMany(array $ids): array;
    public function save(Car $car): void;
    public function delete(string $id): void;
}
