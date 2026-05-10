<?php
declare(strict_types=1);

namespace App\Entity\Doctrine;

use App\Entity\StorageType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'storage_resource')]
#[ORM\UniqueConstraint(name: 'uq_challenge_type', columns: ['challenge_id', 'type'])]
class DbStorageResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: DbChallenge::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DbChallenge $challenge;

    #[ORM\Column(type: 'string', length: 50, enumType: StorageType::class)]
    private StorageType $type;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $credentials = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $label = null;

    public function __construct(DbChallenge $challenge, StorageType $type)
    {
        $this->challenge = $challenge;
        $this->type = $type;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getChallenge(): DbChallenge
    {
        return $this->challenge;
    }

    public function getType(): StorageType
    {
        return $this->type;
    }

    public function getCredentials(): ?array
    {
        return $this->credentials;
    }

    public function setCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }
}
