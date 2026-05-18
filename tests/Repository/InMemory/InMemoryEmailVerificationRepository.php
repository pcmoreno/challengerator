<?php
declare(strict_types=1);

namespace App\Tests\Repository\InMemory;

use App\Entity\Auth\EmailVerification;
use App\Repository\EmailVerificationRepositoryInterface;

class InMemoryEmailVerificationRepository implements EmailVerificationRepositoryInterface
{
    /** @var EmailVerification[] keyed by token */
    private array $records = [];

    public function findByToken(string $token): ?EmailVerification
    {
        return $this->records[$token] ?? null;
    }

    public function findPendingByEmailAndChallenge(string $email, string $challengeName): array
    {
        return array_values(array_filter(
            $this->records,
            fn(EmailVerification $ev) =>
                $ev->getEmail() === $email &&
                $ev->getChallengeId() === $challengeName &&
                $ev->isJoinChallenge()
        ));
    }

    public function save(EmailVerification $verification): void
    {
        $this->records[$verification->getToken()] = $verification;
    }

    public function delete(EmailVerification $verification): void
    {
        unset($this->records[$verification->getToken()]);
    }

    public function count(): int
    {
        return count($this->records);
    }
}
