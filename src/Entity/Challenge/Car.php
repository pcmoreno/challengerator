<?php
declare(strict_types=1);

namespace App\Entity\Challenge;
use Symfony\Component\Uid\Uuid;

class Car
{
    private string $id;
    private string $name;
    private Rating $rating;
    private string $imageUrlA;
    private string $imageUrlB;
    private \DateTime $addedOn;
    private ?\DateTime $updatedOn;
    private string $challengeId;

    private function __construct(string $id, string $name, Rating $rating, string $imageUrlA, string $imageUrlB, \DateTime $addedOn, ?\DateTime $updatedOn, string $challengeId)
    {
        $this->id = $id;
        $this->name = $name;
        $this->rating = $rating;
        $this->imageUrlA = $imageUrlA;
        $this->imageUrlB = $imageUrlB;
        $this->addedOn = $addedOn;
        $this->updatedOn = $updatedOn;
        $this->challengeId = $challengeId;
    }

    public static function create($data): Car
    {
        if(!isset($data['_id'])) {
            $id = Uuid::v4()->jsonSerialize();
        } else {
            $id = $data['_id'];
        }
        return new Car(
            $id,
            $data['name'],
            Rating::createNew(),
            $data['imageUrlA'],
            $data['imageUrlB'],
            new \DateTime(),
            null,
            $data['challengeId'],
        );
    }

    public static function fromCouchData($data): Car
    {
        return new Car(
            $data['_id'],
            $data['name'],
            Rating::fromInt($data['rating']),
            $data['imageUrlA'],
            $data['imageUrlB'],
            new \DateTime($data['addedOn']['date']), //TODO make this be well
            new \DateTime(),
            $data['challengeId'],
        );
    }

    public function toCouchDocument(): \stdClass
    {
        $stdClass = new \stdClass();
        $stdClass->_id = $this->id;
        $stdClass->name = $this->name;
        $stdClass->rating = $this->rating->getRating();
        $stdClass->imageUrlA = $this->imageUrlA;
        $stdClass->imageUrlB = $this->imageUrlB;
        $stdClass->addedOn = $this->addedOn;
        $stdClass->updatedOn = $this->updatedOn;
        $stdClass->challengeId = $this->challengeId;
        return $stdClass;
    }

    public static function empty(): Car
    {
        return Car::create([
                'name' => '',
                'imageUrlA' => '',
                'imageUrlB' => '',
                'challengeId' => ''
            ]
        );
    }


    // --- boilerplate getters and setters --- //
    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getRating(): Rating
    {
        return $this->rating;
    }

    public function setRating(Rating $rating): void
    {
        $this->rating = $rating;
    }

    public function getImageUrlA(): string
    {
        return $this->imageUrlA;
    }

    public function setImageUrlA(string $imageUrlA): void
    {
        $this->imageUrlA = $imageUrlA;
    }

    public function getImageUrlB(): string
    {
        return $this->imageUrlB;
    }

    public function setImageUrlB(string $imageUrlB): void
    {
        $this->imageUrlB = $imageUrlB;
    }

    public function getAddedOn(): \DateTime
    {
        return $this->addedOn;
    }

    public function getUpdatedOn(): \DateTime
    {
        return $this->updatedOn;
    }

    public function setUpdatedOn(\DateTime $updatedOn): void
    {
        $this->updatedOn = $updatedOn;
    }

    public function getChallengeId(): string
    {
        return $this->challengeId;
    }

    public function setChallengeId(string $challengeId): void
    {
        $this->challengeId = $challengeId;
    }
}