<?php
declare(strict_types=1);

namespace App\Services;

use App\Entity\Challenge\Outcome;
use App\Entity\Challenge\Rating;
use App\Repository\CarRepositoryInterface;
use App\Repository\ChallengeRepositoryInterface;
use App\Repository\TransactionInterface;
use App\Repository\VoteLogRepositoryInterface;
use Psr\Log\LoggerInterface;

class RatingRecalculationService
{
    private const STARTING_RATING = 1500;

    public function __construct(
        private readonly ChallengeRepositoryInterface $challengeRepository,
        private readonly CarRepositoryInterface $carRepository,
        private readonly VoteLogRepositoryInterface $voteLogRepository,
        private readonly TransactionInterface $transaction,
        private readonly LoggerInterface $votesLogger,
    ) {
    }

    public function recalculate(string $challengeName): RecalculateReport
    {
        $challenge = $this->challengeRepository->find($challengeName);
        $cars = $this->carRepository->findMany($challenge->getCars());

        $carsById = [];
        foreach ($cars as $car) {
            $car->setRating(Rating::fromInt(self::STARTING_RATING));
            $carsById[$car->getId()] = $car;
        }

        $applied = 0;
        $skipped = 0;

        foreach ($this->voteLogRepository->findAllValid($challengeName) as $entry) {
            $carA = $carsById[$entry->carAId] ?? null;
            $carB = $carsById[$entry->carBId] ?? null;
            if ($carA === null || $carB === null) {
                $this->votesLogger->warning(sprintf(
                    'Recalc skipped vote %s in challenge %s: referenced car missing (carA=%s carB=%s)',
                    $entry->voteId,
                    $challengeName,
                    $entry->carAId,
                    $entry->carBId,
                ));
                $skipped++;
                continue;
            }

            $outcome = Outcome::tryFrom($entry->outcome);
            if ($outcome === null) {
                $this->votesLogger->warning(sprintf('Recalc skipped vote %s: invalid outcome "%s"', $entry->voteId, $entry->outcome));
                $skipped++;
                continue;
            }

            RatingService::compareAndAdjust($carA->getRating(), $carB->getRating(), $outcome);
            $applied++;
        }

        $this->transaction->transactionalWithRetry(function () use ($carsById): void {
            foreach ($carsById as $car) {
                $this->carRepository->save($car);
            }
        });

        $this->votesLogger->notice(sprintf(
            'Recalculated %d cars in challenge %s from %d votes (%d skipped)',
            count($carsById),
            $challengeName,
            $applied,
            $skipped,
        ));

        return new RecalculateReport(count($carsById), $applied, $skipped);
    }
}
