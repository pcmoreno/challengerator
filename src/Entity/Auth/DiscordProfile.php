<?php
declare(strict_types=1);

namespace App\Entity\Auth;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'discord_profile')]
class DiscordProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'discordProfile')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'string', unique: true)]
    private string $discordId;

    #[ORM\Column(type: 'string')]
    private string $discordUsername;

    #[ORM\Column(type: 'string', length: 254, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $avatarUrl = null;

    public function __construct(User $user, string $discordId, string $discordUsername)
    {
        $this->user = $user;
        $this->discordId = $discordId;
        $this->discordUsername = $discordUsername;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDiscordId(): string
    {
        return $this->discordId;
    }

    public function getDiscordUsername(): string
    {
        return $this->discordUsername;
    }

    public function setDiscordUsername(string $discordUsername): void
    {
        $this->discordUsername = $discordUsername;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): void
    {
        $this->avatarUrl = $avatarUrl;
    }
}
