<?php
declare(strict_types=1);

namespace App\Entity\Vote;

final readonly class VoteLogPage
{
    /**
     * @param list<VoteLogEntry> $entries
     */
    public function __construct(
        public array $entries,
        public int $offset,
        public int $pageSize,
        public bool $hasMore,
    ) {
    }

    public function nextOffset(): ?int
    {
        return $this->hasMore ? $this->offset + $this->pageSize : null;
    }

    public function previousOffset(): ?int
    {
        if ($this->offset <= 0) {
            return null;
        }
        return max(0, $this->offset - $this->pageSize);
    }
}
