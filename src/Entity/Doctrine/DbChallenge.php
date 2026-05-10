<?php
declare(strict_types=1);

namespace App\Entity\Doctrine;

use App\Entity\Auth\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'challenge')]
class DbChallenge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $name;

    #[ORM\Column(type: 'string')]
    private string $adminPassword;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isActive = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $allowSelfRegistration = false;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $selfRegistrationCode = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'challenge_user')]
    #[ORM\JoinColumn(name: 'challenge_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $voters;

    #[ORM\OneToMany(targetEntity: DbCar::class, mappedBy: 'challenge', cascade: ['persist', 'remove'])]
    private Collection $cars;

    public function __construct(string $name, string $adminPassword)
    {
        $this->name = $name;
        $this->adminPassword = $adminPassword;
        $this->voters = new ArrayCollection();
        $this->cars = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAdminPassword(): string
    {
        return $this->adminPassword;
    }

    public function setAdminPassword(string $adminPassword): void
    {
        $this->adminPassword = $adminPassword;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function isAllowSelfRegistration(): bool
    {
        return $this->allowSelfRegistration;
    }

    public function setAllowSelfRegistration(bool $allowSelfRegistration): void
    {
        $this->allowSelfRegistration = $allowSelfRegistration;
    }

    public function getSelfRegistrationCode(): ?string
    {
        return $this->selfRegistrationCode;
    }

    public function setSelfRegistrationCode(?string $selfRegistrationCode): void
    {
        $this->selfRegistrationCode = $selfRegistrationCode;
    }

    public function getVoters(): Collection
    {
        return $this->voters;
    }

    public function addVoter(User $user): void
    {
        if (!$this->voters->contains($user)) {
            $this->voters->add($user);
        }
    }

    public function removeVoter(User $user): void
    {
        $this->voters->removeElement($user);
    }

    public function getCars(): Collection
    {
        return $this->cars;
    }
}
