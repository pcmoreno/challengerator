<?php
declare(strict_types=1);

namespace App\Services;

use App\Entity\Challenge\Outcome;
use App\Entity\Challenge\Rating;

class RatingService
{
    protected const K = 100;
    protected const D = 400;

    public static function compareAndAdjust(Rating $ratingA, Rating $ratingB, string $outcome): void
    {
        $oldRatingValueA = $ratingA->getRating();
        $oldRatingValueB = $ratingB->getRating();

        $validatedOutcome = Outcome::create($outcome);
        switch ($validatedOutcome->getOutcome()) {
            case Outcome::DRAW:
                $ratingA->setRating(self::getNewRating($oldRatingValueA, $oldRatingValueB, 0.5));
                $ratingB->setRating(self::getNewRating($oldRatingValueB, $oldRatingValueA, 0.5));
                break;
            case Outcome::RIGHT_WINS:
                $ratingA->setRating(self::getNewRating($oldRatingValueA, $oldRatingValueB, 0));
                $ratingB->setRating(self::getNewRating($oldRatingValueB, $oldRatingValueA, 1));
                break;
            case Outcome::LEFT_WINS:
                $ratingA->setRating(self::getNewRating($oldRatingValueA, $oldRatingValueB, 1));
                $ratingB->setRating(self::getNewRating($oldRatingValueB, $oldRatingValueA, 0));
                break;
        }
    }

    public static function getExpectedWinsForPlayer($yourRating, $opponentRating): float
    {
        $ratio = ($opponentRating - $yourRating) / self::D;
        $e = 1/(1+(pow(10,$ratio)));
        return $e;
    }

    public static function getNewRating($yourRating, $opponentRating, $score): int
    {
        $updatedRating = $yourRating + self::K * ($score - self::getExpectedWinsForPlayer($yourRating, $opponentRating));
        return (int)round($updatedRating);
    }
}