<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

class Voter
{
    private string $id;
    private string $name;
    private RoundOfComparisons $round;
}