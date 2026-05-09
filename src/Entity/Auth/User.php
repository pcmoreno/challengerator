<?php
declare(strict_types=1);

namespace App\Entity\Auth;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Entity
 * @ORM\Table(name="`user`")
 */
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\Column(type="string", length=180, unique=true)
     */
    private string $username;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private ?string $password = null;

    /**
     * @ORM\Column(type="string", length=254, nullable=true)
     */
    private ?string $email = null;

    /**
     * @ORM\Column(type="string", length=45, nullable=true)
     */
    private ?string $ipAddress = null;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $verifiedAt = null;

    /**
     * @ORM\Column(type="json")
     */
    private array $roles = [];

    /**
     * @ORM\OneToOne(targetEntity=DiscordProfile::class, mappedBy="user", cascade={"persist", "remove"})
     */
    private ?DiscordProfile $discordProfile = null;

    /**
     * @ORM\ManyToMany(targetEntity="App\Entity\Doctrine\DbChallenge", inversedBy="voters")
     * @ORM\JoinTable(name="challenge_user",
     *     joinColumns={@ORM\JoinColumn(name="user_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="challenge_id", referencedColumnName="id")}
     * )
     */
    private Collection $challenges;

    public function __construct(string $username)
    {
        $this->username = $username;
        $this->challenges = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        return array_unique(array_merge($this->roles, ['ROLE_USER', 'ROLE_VOTER']));
    }

    public function addRole(string $role): void
    {
        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): void
    {
        $this->ipAddress = $ipAddress;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }

    public function verify(): void
    {
        $this->verifiedAt = new \DateTimeImmutable();
    }

    public function getDiscordProfile(): ?DiscordProfile
    {
        return $this->discordProfile;
    }

    public function setDiscordProfile(?DiscordProfile $discordProfile): void
    {
        $this->discordProfile = $discordProfile;
    }

    public function getChallenges(): Collection
    {
        return $this->challenges;
    }

    public function addChallenge(\App\Entity\Doctrine\DbChallenge $challenge): void
    {
        if (!$this->challenges->contains($challenge)) {
            $this->challenges->add($challenge);
        }
    }

    public function eraseCredentials(): void
    {
    }

    public function getSalt(): ?string
    {
        return null;
    }
}
