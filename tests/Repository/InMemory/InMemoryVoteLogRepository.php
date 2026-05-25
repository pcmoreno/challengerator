<?php
declare(strict_types=1);

namespace App\Tests\Repository\InMemory;

use App\Entity\Vote\VoteLogEntry;
use App\Entity\Vote\VoteLogFilters;
use App\Entity\Vote\VoteLogPage;
use App\Repository\VoteLogRepositoryInterface;

class InMemoryVoteLogRepository implements VoteLogRepositoryInterface
{
    /** @var array<string, array<string, VoteLogEntry>> indexed by challengeName then voteId */
    private array $byChallenge = [];

    /** @var array<string, true> */
    public array $ensured = [];

    public function ensureDatabaseForChallenge(string $challengeName): void
    {
        $this->ensured[$challengeName] = true;
        $this->byChallenge[$challengeName] ??= [];
    }

    public function logVote(VoteLogEntry $entry): void
    {
        $this->byChallenge[$entry->challengeName][$entry->voteId] = $entry;
    }

    public function findByChallenge(
        string $challengeName,
        VoteLogFilters $filters,
        int $pageSize,
        int $offset = 0,
    ): VoteLogPage {
        $all = $this->matchedEntries($challengeName, $filters);
        usort($all, static fn (VoteLogEntry $a, VoteLogEntry $b): int => $b->votedAt <=> $a->votedAt);

        $window = array_slice($all, $offset, $pageSize + 1);
        $hasMore = count($window) > $pageSize;
        if ($hasMore) {
            $window = array_slice($window, 0, $pageSize);
        }

        return new VoteLogPage(array_values($window), $offset, $pageSize, $hasMore);
    }

    public function invalidate(string $challengeName, string $voteId, string $invalidatedBy): void
    {
        if (!isset($this->byChallenge[$challengeName][$voteId])) {
            throw new \RuntimeException(sprintf('Vote "%s" not found in challenge "%s"', $voteId, $challengeName));
        }
        $entry = $this->byChallenge[$challengeName][$voteId];
        if ($entry->status === 'invalidated') {
            return;
        }
        $this->byChallenge[$challengeName][$voteId] = $entry->withInvalidation(new \DateTimeImmutable(), $invalidatedBy);
    }

    public function findAllValid(string $challengeName): iterable
    {
        $entries = array_values($this->byChallenge[$challengeName] ?? []);
        $entries = array_filter($entries, static fn (VoteLogEntry $entry): bool => $entry->status === 'valid');
        usort($entries, static fn (VoteLogEntry $a, VoteLogEntry $b): int => $a->votedAt <=> $b->votedAt);
        yield from $entries;
    }

    /**
     * @return list<VoteLogEntry>
     */
    private function matchedEntries(string $challengeName, VoteLogFilters $filters): array
    {
        $entries = array_values($this->byChallenge[$challengeName] ?? []);
        return array_values(array_filter($entries, static function (VoteLogEntry $entry) use ($filters): bool {
            if ($filters->status !== null && $entry->status !== $filters->status) {
                return false;
            }
            if ($filters->voterId !== null && $entry->voterId !== $filters->voterId) {
                return false;
            }
            if ($filters->carId !== null && $entry->carAId !== $filters->carId && $entry->carBId !== $filters->carId) {
                return false;
            }
            return true;
        }));
    }
}
