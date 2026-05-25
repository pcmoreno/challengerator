<?php
declare(strict_types=1);

namespace App\Repository\Couch;

use App\Entity\Vote\VoteLogEntry;
use App\Entity\Vote\VoteLogFilters;
use App\Entity\Vote\VoteLogPage;
use App\Exception\CouchDBException;
use App\Repository\VoteLogRepositoryInterface;

final class CouchVoteLogRepository implements VoteLogRepositoryInterface
{
    private const DB_PREFIX = 'votes_';
    private const VALID_DB_NAME = '/^[a-z][a-z0-9_$()+\/-]*$/';

    /** @var array<string,true> */
    private array $ensuredDatabases = [];

    public function __construct(private readonly CouchClient $couchClient) {}

    public function ensureDatabaseForChallenge(string $challengeName): void
    {
        $dbName = $this->dbName($challengeName);
        if (isset($this->ensuredDatabases[$dbName])) {
            return;
        }

        if (!$this->couchClient->databaseExists($dbName)) {
            $this->couchClient->createDatabase($dbName);
        }
        $this->ensureIndexes($dbName);

        $this->ensuredDatabases[$dbName] = true;
    }

    public function logVote(VoteLogEntry $entry): void
    {
        $this->couchClient->storeDoc($this->dbName($entry->challengeName), $entry->toCouchDocument());
    }

    public function findByChallenge(
        string $challengeName,
        VoteLogFilters $filters,
        int $pageSize,
        int $offset = 0,
    ): VoteLogPage {
        $dbName = $this->dbName($challengeName);
        if (!$this->couchClient->databaseExists($dbName)) {
            return new VoteLogPage([], $offset, $pageSize, false);
        }

        $documents = $this->couchClient->find(
            db: $dbName,
            selector: $this->buildSelector($filters),
            sort: [['voted_at' => 'desc']],
            limit: $pageSize + 1,
            skip: $offset,
        );

        $hasMore = count($documents) > $pageSize;
        if ($hasMore) {
            $documents = array_slice($documents, 0, $pageSize);
        }

        $entries = array_map(static fn (array $doc): VoteLogEntry => VoteLogEntry::fromCouchDocument($doc), $documents);

        return new VoteLogPage(array_values($entries), $offset, $pageSize, $hasMore);
    }

    public function invalidate(string $challengeName, string $voteId, string $invalidatedBy): void
    {
        $dbName = $this->dbName($challengeName);
        $document = $this->couchClient->getDoc($dbName, $voteId);
        if ($document === null) {
            throw new CouchDBException(sprintf('Vote "%s" not found in challenge "%s"', $voteId, $challengeName));
        }

        $entry = VoteLogEntry::fromCouchDocument($document);
        if ($entry->status === 'invalidated') {
            return;
        }

        $updated = $entry->withInvalidation(new \DateTimeImmutable(), $invalidatedBy);
        $this->couchClient->storeDoc($dbName, $updated->toCouchDocument());
    }

    public function findAllValid(string $challengeName): iterable
    {
        $dbName = $this->dbName($challengeName);
        if (!$this->couchClient->databaseExists($dbName)) {
            return;
        }

        $pageSize = 200;
        $offset = 0;

        while (true) {
            $documents = $this->couchClient->find(
                db: $dbName,
                selector: ['status' => 'valid', 'voted_at' => ['$gt' => null]],
                sort: [['voted_at' => 'asc'], ['_id' => 'asc']],
                limit: $pageSize,
                skip: $offset,
            );

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

    private function ensureIndexes(string $db): void
    {
        $this->couchClient->createIndex($db, ['voted_at'], 'idx_voted_at');
        $this->couchClient->createIndex($db, ['voter.id', 'voted_at'], 'idx_voter_voted_at');
        $this->couchClient->createIndex($db, ['car_a.id', 'voted_at'], 'idx_car_a_voted_at');
        $this->couchClient->createIndex($db, ['car_b.id', 'voted_at'], 'idx_car_b_voted_at');
        $this->couchClient->createIndex($db, ['status', 'voted_at', '_id'], 'idx_status_voted_at');
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
}
