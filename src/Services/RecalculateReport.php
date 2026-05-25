<?php
declare(strict_types=1);

namespace App\Services;

final readonly class RecalculateReport
{
    public function __construct(
        public int $carsUpdated,
        public int $votesApplied,
        public int $votesSkipped,
    ) {
    }
}
