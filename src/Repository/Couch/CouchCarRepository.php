<?php
declare(strict_types=1);

namespace App\Repository\Couch;

use App\Entity\Challenge\Car;
use App\Repository\CarRepositoryInterface;
use PHPOnCouch\CouchClient;
use PHPOnCouch\Exceptions\CouchNotFoundException;

class CouchCarRepository implements CarRepositoryInterface
{
    private CouchClient $client;

    public function __construct(string $dsn)
    {
        $this->client = new CouchClient($dsn, 'cars');
    }

    public function find(string $id): Car
    {
        $doc = $this->client->getDoc($id);
        return Car::fromCouchData(json_decode(json_encode($doc), true));
    }

    public function findMany(array $ids): array
    {
        return array_map(fn($id) => $this->find($id), $ids);
    }

    public function save(Car $car): void
    {
        $doc = $car->toCouchDocument();
        try {
            $existing = $this->client->getDoc($car->getId());
            $doc->_rev = $existing->_rev;
        } catch (CouchNotFoundException $e) {
            // new document — no _rev needed
        }
        $this->client->storeDoc($doc);
    }

    public function delete(string $id): void
    {
        $this->client->deleteDoc($this->client->getDoc($id));
    }
}
