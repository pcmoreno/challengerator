<?php
declare(strict_types=1);

namespace App\Repository\Couch;

use App\Entity\Challenge\Voter;
use App\Repository\VoterRepositoryInterface;
use PHPOnCouch\CouchClient;
use PHPOnCouch\Exceptions\CouchNotFoundException;

class CouchVoterRepository implements VoterRepositoryInterface
{
    private CouchClient $client;

    public function __construct(string $dsn)
    {
        $this->client = new CouchClient($dsn, 'voters');
    }

    public function find(string $id): ?Voter
    {
        try {
            $doc = $this->client->getDoc($id);
            return Voter::fromCouchDocument(json_decode(json_encode($doc), true));
        } catch (CouchNotFoundException $e) {
            return null;
        }
    }

    public function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $response = $this->client->keys($ids)->include_docs(true)->getAllDocs();
        $byId = [];
        foreach ($response->rows as $row) {
            if (isset($row->doc)) {
                $byId[$row->id] = Voter::fromCouchDocument(json_decode(json_encode($row->doc), true));
            }
        }
        return array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
    }

    public function findByName(string $name): ?Voter
    {
        $results = $this->client->find(['name' => $name]);
        if (empty($results)) {
            return null;
        }
        return Voter::fromCouchDocument(json_decode(json_encode($results[0]), true));
    }

    public function hasVoterFromIpForChallenge(string $ip, string $challengeName): bool
    {
        $results = $this->client->find(['ipAddress' => ['$eq' => $ip]]);
        $results = json_decode(json_encode($results), true);
        foreach ($results as $item) {
            if (array_key_exists($challengeName, $item['challenges'])) {
                return true;
            }
        }
        return false;
    }

    public function save(Voter $voter): void
    {
        $doc = $voter->toCouchDocument();
        try {
            $existing = $this->client->getDoc($voter->getId());
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

    public function markCarsVoted(string $voterId, string $challengeName, array $carIds): void
    {
        throw new \LogicException('CouchVoterRepository is no longer wired; use DoctrineVoterRepository.');
    }
}
