<?php
declare(strict_types=1);

namespace App\Exception;

class ChallengeDoesNotExistException extends BusinessLogicException
{
    public function __construct(string $challengeName)
    {
        parent::__construct("Challenge '$challengeName' no longer exists.");
    }
}
