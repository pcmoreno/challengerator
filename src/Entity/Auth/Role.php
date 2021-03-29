<?php
declare(strict_types=1);

namespace App\Entity\Auth;

class Role
{
    const ADMIN = 'admin';
    const VOTER = 'voter';
    const VOTER_OF_A_DIFFERENT_CHALLENGE = 'not this challenge';
    const NONE = 'not an active user';
}