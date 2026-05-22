<?php
declare(strict_types=1);

namespace App\Repository\Couch;

use App\Entity\Challenge\Challenge;
use PHPOnCouch\CouchClient;

/**
 * CouchDB-backed challenge repository — not wired in services.yaml, reserved for future use.
 * Does not implement ChallengeRepositoryInterface until it matches the full contract.
 */
class CouchChallengeRepository
{
    private const SYSTEM_DBS = ['cars', 'voters', '_users', '_replicator', '_global_changes', 'codes'];

    public function __construct(private readonly string $dsn) {}

    public function create(string $name): void
    {
        $this->client($name)->createDatabase();
    }

    public function find(string $name): Challenge
    {
        $doc = $this->client($name)->getDoc('info');
        return Challenge::fromCouchDocument(json_decode(json_encode($doc), true));
    }

    public function save(Challenge $challenge): void
    {
        $this->client($challenge->getName())->storeDoc($challenge->toCouchDocument());
    }

    public function listNames(): array
    {
        $all = (array) $this->client('server')->listDatabases();
        return array_values(array_filter($all, fn($db) => !in_array($db, self::SYSTEM_DBS)));
    }

    private function client(string $db): CouchClient
    {
        return new CouchClient($this->dsn, $db);
    }
}
