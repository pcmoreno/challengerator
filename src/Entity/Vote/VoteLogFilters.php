<?php
declare(strict_types=1);

namespace App\Entity\Vote;

final readonly class VoteLogFilters
{
    public function __construct(
        public ?string $voterId = null,
        public ?string $carId = null,
        public ?string $status = null,
    ) {
    }

    public function hasAny(): bool
    {
        return $this->voterId !== null || $this->carId !== null || $this->status !== null;
    }
}
