<?php
declare(strict_types=1);

namespace App\Repository\Couch;

use App\Entity\Vote\VoteLogEntry;
use App\Entity\Vote\VoteLogFilters;
use App\Entity\Vote\VoteLogPage;
use App\Repository\VoteLogRepositoryInterface;
use PHPOnCouch\CouchClient;
use PHPOnCouch\Exceptions\CouchNotFoundException;

final class CouchVoteLogRepository implements VoteLogRepositoryInterface
{
    private const DB_PREFIX = 'votes_';
    private const VALID_DB_NAME = '/^[a-z][a-z0-9_$()+\/-]*$/';

    /** @var array<string,true> */
    private array $ensuredDatabases = [];

    public function __construct(private readonly string $dsn)
    {
    }

    public function ensureDatabaseForChallenge(string $challengeName): void
    {
        $dbName = $this->dbName($challengeName);
        if (isset($this->ensuredDatabases[$dbName])) {
            return;
        }

        $client = $this->client($dbName);
        if (!$client->databaseExists()) {
            $client->createDatabase();
        }
        $this->ensureIndexes($client);

        $this->ensuredDatabases[$dbName] = true;
    }

    public function logVote(VoteLogEntry $entry): void
    {
        $client = $this->client($this->dbName($entry->challengeName));
        $client->storeDoc((object) $entry->toCouchDocument());
    }

    public function findByChallenge(
        string $challengeName,
        VoteLogFilters $filters,
        int $pageSize,
        int $offset = 0,
    ): VoteLogPage {
        $dbName = $this->dbName($challengeName);
        $client = $this->client($dbName);

        if (!$client->databaseExists()) {
            return new VoteLogPage([], $offset, $pageSize, false);
        }

        $selector = $this->buildSelector($filters);
        $client->asArray();
        $client->setQueryParameters([
            'limit' => $pageSize + 1,
            'skip' => $offset,
            'sort' => [['voted_at' => 'desc']],
        ]);

        try {
            $documents = $client->find($selector);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                sprintf('CouchDB find failed for challenge "%s": %s', $challengeName, $exception->getMessage()),
                previous: $exception,
            );
        }

        $hasMore = count($documents) > $pageSize;
        if ($hasMore) {
            $documents = array_slice($documents, 0, $pageSize);
        }

        $entries = array_map(static fn (array $doc): VoteLogEntry => VoteLogEntry::fromCouchDocument($doc), $documents);

        return new VoteLogPage(array_values($entries), $offset, $pageSize, $hasMore);
    }

    public function invalidate(string $challengeName, string $voteId, string $invalidatedBy): void
    {
        $client = $this->client($this->dbName($challengeName));
        $client->asArray();

        try {
            $document = $client->getDoc($voteId);
        } catch (CouchNotFoundException $exception) {
            throw new \RuntimeException(sprintf('Vote "%s" not found in challenge "%s"', $voteId, $challengeName), previous: $exception);
        }

        $entry = VoteLogEntry::fromCouchDocument($document);
        if ($entry->status === 'invalidated') {
            return;
        }

        $updated = $entry->withInvalidation(new \DateTimeImmutable(), $invalidatedBy);
        $client->storeDoc((object) $updated->toCouchDocument());
    }

    public function findAllValid(string $challengeName): iterable
    {
        $dbName = $this->dbName($challengeName);
        $client = $this->client($dbName);

        if (!$client->databaseExists()) {
            return;
        }

        $pageSize = 200;
        $offset = 0;

        while (true) {
            $client->asArray();
            $client->setQueryParameters([
                'limit' => $pageSize,
                'skip' => $offset,
                'sort' => [['voted_at' => 'asc']],
            ]);

            $documents = $client->find([
                'status' => 'valid',
                'voted_at' => ['$gt' => null],
            ]);

            if ($documents === []) {
                return;
            }

            foreach ($documents as $document) {
                yield VoteLogEntry::fromCouchDocument($document);
            }

            if (count($documents) < $pageSize) {
                return;
            }
            $offset += $pageSize;
        }
    }

    private function ensureIndexes(CouchClient $client): void
    {
        $client->createIndex(['voted_at'], 'idx_voted_at');
        $client->createIndex(['voter.id', 'voted_at'], 'idx_voter_voted_at');
        $client->createIndex(['car_a.id', 'voted_at'], 'idx_car_a_voted_at');
        $client->createIndex(['car_b.id', 'voted_at'], 'idx_car_b_voted_at');
        $client->createIndex(['status', 'voted_at'], 'idx_status_voted_at');
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSelector(VoteLogFilters $filters): array
    {
        $selector = ['voted_at' => ['$gt' => null]];

        if ($filters->status !== null) {
            $selector['status'] = $filters->status;
        }
        if ($filters->voterId !== null) {
            $selector['voter.id'] = $filters->voterId;
        }
        if ($filters->carId !== null) {
            $selector['$or'] = [
                ['car_a.id' => $filters->carId],
                ['car_b.id' => $filters->carId],
            ];
        }

        return $selector;
    }

    private function dbName(string $challengeName): string
    {
        $sanitized = strtolower($challengeName);
        $sanitized = preg_replace('/[^a-z0-9_$()+\/-]/', '_', $sanitized) ?? '_';
        $name = self::DB_PREFIX . $sanitized;

        if (!preg_match(self::VALID_DB_NAME, $name)) {
            throw new \InvalidArgumentException(sprintf('Could not derive valid CouchDB name from challenge "%s"', $challengeName));
        }

        return $name;
    }

    private function client(string $db): CouchClient
    {
        return new CouchClient($this->dsn, $db);
    }
}
