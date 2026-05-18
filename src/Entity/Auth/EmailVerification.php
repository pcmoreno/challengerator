<?php
declare(strict_types=1);

namespace App\Entity\Auth;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'email_verification')]
class EmailVerification
{
    const TYPE_ACTIVATION = 'activation';
    const TYPE_JOIN_CHALLENGE = 'join_challenge';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    #[ORM\Column(type: 'string', length: 254)]
    private string $email;

    #[ORM\Column(type: 'string', unique: true)]
    private string $token;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'string', length: 20)]
    private string $type;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $challengeId = null;

    public function __construct(?User $user, string $email, string $token, \DateTimeImmutable $expiresAt, string $type, ?string $challengeId = null)
    {
        $this->user = $user;
        $this->email = $email;
        $this->token = $token;
        $this->expiresAt = $expiresAt;
        $this->type = $type;
        $this->challengeId = $challengeId;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getChallengeId(): ?string
    {
        return $this->challengeId;
    }

    public function isActivation(): bool
    {
        return $this->type === self::TYPE_ACTIVATION;
    }

    public function isJoinChallenge(): bool
    {
        return $this->type === self::TYPE_JOIN_CHALLENGE;
    }
}
