<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

use PHPUnit\Util\Exception;

class Outcome
{
    public const LEFT_WINS = 'left';
    public const RIGHT_WINS = 'right';
    public const DRAW = 'draw';

    private string $outcome;

    private function __construct($outcome)
    {
        $this->outcome = $outcome;
    }

    public static function create(string $outcome): self
    {
        if (!in_array($outcome, [self::LEFT_WINS, self::RIGHT_WINS, self::DRAW])) {
            throw new Exception('Invalid Outcome');
        }
        return new static($outcome);
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }
}