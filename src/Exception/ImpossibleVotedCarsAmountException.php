<?php
declare(strict_types=1);

namespace App\Exception;

class ImpossibleVotedCarsAmountException extends BusinessLogicException
{
    public function __construct(int $count, string $voterId, string $challengeName)
    {
        $message = "Data corruption: odd number of voted cars ({$count}) for voter {$voterId} in challenge {$challengeName}";
        parent::__construct($message);
    }
}
