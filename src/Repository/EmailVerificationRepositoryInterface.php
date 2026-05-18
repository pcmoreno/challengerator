<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Auth\EmailVerification;

interface EmailVerificationRepositoryInterface
{
    public function findByToken(string $token): ?EmailVerification;

    /** @return EmailVerification[] */
    public function findPendingByEmailAndChallenge(string $email, string $challengeName): array;

    public function save(EmailVerification $verification): void;

    public function delete(EmailVerification $verification): void;
}
