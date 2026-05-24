<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Vote\VoteLogEntry;
use App\Entity\Vote\VoteLogFilters;
use App\Entity\Vote\VoteLogPage;

interface VoteLogRepositoryInterface
{
    public function ensureDatabaseForChallenge(string $challengeName): void;

    public function logVote(VoteLogEntry $entry): void;

    public function findByChallenge(
        string $challengeName,
        VoteLogFilters $filters,
        int $pageSize,
        int $offset = 0,
    ): VoteLogPage;

    public function invalidate(string $challengeName, string $voteId, string $invalidatedBy): void;

    /**
     * @return iterable<VoteLogEntry> ordered by voted_at ASC
     */
    public function findAllValid(string $challengeName): iterable;
}
