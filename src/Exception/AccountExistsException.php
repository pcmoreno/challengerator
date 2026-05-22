<?php
declare(strict_types=1);

namespace App\Exception;

class AccountExistsException extends BusinessLogicException
{
    public const MESSAGE = 'An account already exists for this email address. Please log in to join the challenge.';

    public function __construct(public readonly string $challengeName)
    {
        parent::__construct(self::MESSAGE);
    }
}
