<?php
declare(strict_types=1);

namespace App\Entity\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'car')]
class DbCar
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: DbChallenge::class, inversedBy: 'cars')]
    #[ORM\JoinColumn(nullable: false)]
    private DbChallenge $challenge;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'string')]
    private string $imageUrlA;

    #[ORM\Column(type: 'string')]
    private string $imageUrlB;

    #[ORM\Column(type: 'integer', options: ['default' => 1500])]
    private int $rating = 1500;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $addedOn;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedOn = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(string $id, DbChallenge $challenge, string $name, string $imageUrlA, string $imageUrlB)
    {
        $this->id = $id;
        $this->challenge = $challenge;
        $this->name = $name;
        $this->imageUrlA = $imageUrlA;
        $this->imageUrlB = $imageUrlB;
        $this->addedOn = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getChallenge(): DbChallenge
    {
        return $this->challenge;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getImageUrlA(): string
    {
        return $this->imageUrlA;
    }

    public function getImageUrlB(): string
    {
        return $this->imageUrlB;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(int $rating): void
    {
        $this->rating = $rating;
        $this->updatedOn = new \DateTimeImmutable();
    }

    public function getAddedOn(): \DateTimeImmutable
    {
        return $this->addedOn;
    }

    public function getUpdatedOn(): ?\DateTimeImmutable
    {
        return $this->updatedOn;
    }
}
