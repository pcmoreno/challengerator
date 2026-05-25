<?php
declare(strict_types=1);

namespace App\Message;

final readonly class LogVoteMessage
{
    public function __construct(
        public string $voteId,
        public string $challengeName,
        public string $voterId,
        public string $voterName,
        public string $carAId,
        public string $carAName,
        public int $carARatingBefore,
        public string $carBId,
        public string $carBName,
        public int $carBRatingBefore,
        public string $outcome,
        public string $votedAtIso8601,
    ) {
    }
}
