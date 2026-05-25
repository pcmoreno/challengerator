<?php
declare(strict_types=1);

namespace App\Tests\Repository\Couch;

use App\Entity\Vote\VoteLogEntry;
use App\Entity\Vote\VoteLogFilters;
use App\Repository\Couch\CouchClient;
use App\Repository\Couch\CouchDsn;
use App\Repository\Couch\CouchVoteLogRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Integration test against a running CouchDB container. Skips when COUCHDB_URL is unset.
 * @group integration
 */
class CouchVoteLogRepositoryTest extends TestCase
{
    private string $challengeName;
    private CouchClient $couchClient;
    private CouchVoteLogRepository $repository;

    protected function setUp(): void
    {
        $dsn = getenv('COUCHDB_URL') ?: ($_ENV['COUCHDB_URL'] ?? null);
        if (!$dsn) {
            $this->markTestSkipped('COUCHDB_URL not set');
        }
        $dsn = str_contains((string) $dsn, '://') ? (string) $dsn : 'http://' . $dsn;
        $this->challengeName = 'test_' . bin2hex(random_bytes(4));
        $this->couchClient = new CouchClient(CouchDsn::fromString($dsn), HttpClient::create());
        $this->repository = new CouchVoteLogRepository($this->couchClient);
    }

    protected function tearDown(): void
    {
        try {
            $this->couchClient->deleteDatabase('votes_' . $this->challengeName);
        } catch (\Throwable) {
            // best effort cleanup
        }
    }

    public function test_ensureDatabaseForChallenge_is_idempotent(): void
    {
        $this->repository->ensureDatabaseForChallenge($this->challengeName);
        $this->repository->ensureDatabaseForChallenge($this->challengeName);

        $this->assertTrue($this->couchClient->databaseExists('votes_' . $this->challengeName));
    }

    public function test_logVote_then_findByChallenge_round_trips(): void
    {
        $this->repository->ensureDatabaseForChallenge($this->challengeName);
        $entry = $this->makeEntry('vote-1');
        $this->repository->logVote($entry);

        $page = $this->repository->findByChallenge($this->challengeName, new VoteLogFilters(), 10);

        $this->assertCount(1, $page->entries);
        $this->assertSame('vote-1', $page->entries[0]->voteId);
        $this->assertSame($this->challengeName, $page->entries[0]->challengeName);
        $this->assertSame('left', $page->entries[0]->outcome);
        $this->assertSame('valid', $page->entries[0]->status);
        $this->assertSame(1500, $page->entries[0]->carARatingBefore);
    }

    public function test_findByChallenge_filters_by_voter(): void
    {
        $this->repository->ensureDatabaseForChallenge($this->challengeName);
        $this->repository->logVote($this->makeEntry('v1', voterId: 'paulo'));
        $this->repository->logVote($this->makeEntry('v2', voterId: 'alice'));

        $page = $this->repository->findByChallenge($this->challengeName, new VoteLogFilters(voterId: 'alice'), 10);

        $this->assertCount(1, $page->entries);
        $this->assertSame('v2', $page->entries[0]->voteId);
    }

    public function test_findByChallenge_filters_by_car_in_either_slot(): void
    {
        $this->repository->ensureDatabaseForChallenge($this->challengeName);
        $this->repository->logVote($this->makeEntry('v1', carAId: 'mustang'));
        $this->repository->logVote($this->makeEntry('v2', carBId: 'mustang'));
        $this->repository->logVote($this->makeEntry('v3', carAId: 'other-a', carBId: 'other-b'));

        $page = $this->repository->findByChallenge($this->challengeName, new VoteLogFilters(carId: 'mustang'), 10);

        $ids = array_map(static fn (VoteLogEntry $entry): string => $entry->voteId, $page->entries);
        sort($ids);
        $this->assertSame(['v1', 'v2'], $ids);
    }

    public function test_invalidate_marks_vote_and_findAllValid_skips_it(): void
    {
        $this->repository->ensureDatabaseForChallenge($this->challengeName);
        $this->repository->logVote($this->makeEntry('keep', votedAt: '2026-05-01T10:00:00+00:00'));
        $this->repository->logVote($this->makeEntry('kill', votedAt: '2026-05-01T11:00:00+00:00'));

        $this->repository->invalidate($this->challengeName, 'kill', 'admin');

        $valid = [];
        foreach ($this->repository->findAllValid($this->challengeName) as $entry) {
            $valid[] = $entry->voteId;
        }
        $this->assertSame(['keep'], $valid);
    }

    public function test_pagination_returns_hasMore_when_extra_results_exist(): void
    {
        $this->repository->ensureDatabaseForChallenge($this->challengeName);
        for ($i = 0; $i < 5; $i++) {
            $this->repository->logVote($this->makeEntry(sprintf('v%02d', $i), votedAt: sprintf('2026-05-01T10:%02d:00+00:00', $i)));
        }

        $firstPage = $this->repository->findByChallenge($this->challengeName, new VoteLogFilters(), 2);
        $secondPage = $this->repository->findByChallenge($this->challengeName, new VoteLogFilters(), 2, 2);

        $this->assertCount(2, $firstPage->entries);
        $this->assertTrue($firstPage->hasMore);
        $this->assertCount(2, $secondPage->entries);
        $this->assertTrue($secondPage->hasMore);
    }

    private function makeEntry(
        string $voteId,
        string $voterId = 'paulo',
        string $carAId = 'mustang',
        string $carBId = 'camaro',
        string $votedAt = '2026-05-01T10:00:00+00:00',
    ): VoteLogEntry {
        return new VoteLogEntry(
            voteId: $voteId,
            challengeName: $this->challengeName,
            voterId: $voterId,
            voterName: 'Paulo',
            carAId: $carAId,
            carAName: 'Car A',
            carARatingBefore: 1500,
            carBId: $carBId,
            carBName: 'Car B',
            carBRatingBefore: 1500,
            outcome: 'left',
            votedAt: new \DateTimeImmutable($votedAt),
        );
    }
}
