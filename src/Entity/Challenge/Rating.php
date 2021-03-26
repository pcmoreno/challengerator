<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

class Rating
{
    private int $rating;
    private const STARTING_RATING = 1500;

    private function __construct($rating)
    {
        $this->rating = $rating;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(int $number): void
    {
        $this->rating = $number;
    }

    public static function createNew(): self
    {
        return new Rating(self::STARTING_RATING);
    }

    public static function fromInt(int $int): self
    {
        return new Rating($int);
    }
}