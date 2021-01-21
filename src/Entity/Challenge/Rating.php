<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

class Rating
{
    private int $rating;

    public function __construct()
    {
        $this->rating = 1500;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(int $number): void
    {
        $this->rating = $number;
    }
}