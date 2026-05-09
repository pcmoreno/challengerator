<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

enum Outcome: string
{
    case LeftWins = 'left';
    case RightWins = 'right';
    case Draw = 'draw';
}
