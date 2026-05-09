<?php
declare(strict_types=1);

namespace App\Services;

use App\Entity\Challenge\Outcome;
use App\Entity\Challenge\Rating;

class RatingService
{
    protected const K = 100;
    protected const D = 400;

    public static function compareAndAdjust(Rating $ratingA, Rating $ratingB, Outcome $outcome): void
    {
        $oldRatingValueA = $ratingA->getRating();
        $oldRatingValueB = $ratingB->getRating();

        match ($outcome) {
            Outcome::Draw => [
                $ratingA->setRating(self::getNewRating($oldRatingValueA, $oldRatingValueB, 0.5)),
                $ratingB->setRating(self::getNewRating($oldRatingValueB, $oldRatingValueA, 0.5)),
            ],
            Outcome::RightWins => [
                $ratingA->setRating(self::getNewRating($oldRatingValueA, $oldRatingValueB, 0)),
                $ratingB->setRating(self::getNewRating($oldRatingValueB, $oldRatingValueA, 1)),
            ],
            Outcome::LeftWins => [
                $ratingA->setRating(self::getNewRating($oldRatingValueA, $oldRatingValueB, 1)),
                $ratingB->setRating(self::getNewRating($oldRatingValueB, $oldRatingValueA, 0)),
            ],
        };
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