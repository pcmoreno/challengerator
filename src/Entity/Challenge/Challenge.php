<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

use DateInterval;
use Symfony\Component\Uid\Uuid;

class Challenge
{
    private string $id;
    private string $name;
    private array $cars;
    private array $voters;
    private bool $isActive;
    private string $owner;
    private ?string $revision;
    private ?string $adminToken;
    private ?int $adminTokenExpirationDate;
    private bool $allowSelfRegistration;
    private ?string $selfRegistrationCode;

    private function __construct(string $id, string $name, array $cars, array $voters, bool $isActive, string $owner)
    {
        $this->id = $id;
        $this->name = $name;
        $this->cars = $cars;
        $this->voters = $voters;
        $this->isActive = $isActive;
        $this->owner = $owner;
        $this->revision = null;
        $this->adminToken = null;
        $this->adminTokenExpirationDate = null;
        $this->allowSelfRegistration = false;
        $this->selfRegistrationCode = null;
    }

    public static function create(string $name, string $owner): Challenge
    {
        return new Challenge('info', $name, [], [], false, $owner);
    }

    public function addParticipant(Car $car, Voter $voter): void
    {
        $this->addVoterToChallenge($voter);
        $this->addCarToChallenge($car);
    }

    public function addCarToChallenge(Car $car): void
    {
        foreach ($this->cars as $carInChallengeId) {
            if ($car->getId() === $carInChallengeId) {
                throw new \Exception('Car Already Added');
            }
        }
        array_push($this->cars, $car->getId());
    }

    public function addVoterToChallenge(Voter $voter): void
    {
        foreach ($this->voters as $voterInChallengeId) {
            if ($voter->getId() === $voterInChallengeId) {
                throw new \Exception('Voter Already Added');
            }
        }
        array_push($this->voters, $voter->getId());
    }

    public function removeVoterFromChallenge($voterId): void
    {
        if (in_array($voterId, $this->voters)) {
            $key = array_search($voterId, $this->voters);
            unset($this->voters[$key]);
        }
    }

    public function removeCarFromChallenge($carId): void
    {
        if (in_array($carId, $this->cars)) {
            $key = array_search($carId, $this->cars);
            unset($this->cars[$key]);
        }
    }

    public function toCouchDocument(): \stdClass
    {
        $stdclass = new \stdClass();
        $stdclass->_id = $this->id;
        $stdclass->name = $this->name;
        $stdclass->cars = $this->cars;
        $stdclass->voters = $this->voters;
        $stdclass->isActive = $this->isActive;
        $stdclass->owner = $this->owner;
        if ($this->revision !== null) {
            $stdclass->_rev = $this->revision;
        }
        if ($this->adminToken !== null) {
            $stdclass->adminToken = $this->adminToken;
        }
        if ($this->adminTokenExpirationDate !== null) {
            $stdclass->adminTokenExpirationDate = $this->adminTokenExpirationDate;
        }
        $stdclass->allowSelfRegistration = $this->allowSelfRegistration;
         if (null !== $this->selfRegistrationCode) {
             $stdclass->selfRegistrationCode =  $this->selfRegistrationCode;
        };
        return $stdclass;
    }

    public static function fromArray(array $doc): Challenge
    {
        $challenge = new Challenge(
            $doc['id'],
            $doc['name'],
            $doc['cars'],
            $doc['voters'],
            $doc['isActive'],
            $doc['owner']
        );
        $challenge->adminToken = $doc['adminToken'] ?? null;
        $challenge->adminTokenExpirationDate = $doc['adminTokenExpirationDate'] ?? null;
        $challenge->allowSelfRegistration = $doc['allowSelfRegistration'] ?? false;
        $challenge->selfRegistrationCode = $doc['selfRegistrationCode'] ?? null;

        return $challenge;
    }

    public static function fromCouchDocument(array $doc): Challenge
    {
        $challenge = self::fromArray([
            'id'                   => $doc['_id'],
            'name'                 => $doc['name'],
            'cars'                 => $doc['cars'],
            'voters'               => $doc['voters'],
            'isActive'             => $doc['isActive'],
            'owner'                => $doc['owner'],
            'adminToken'           => $doc['adminToken'] ?? null,
            'adminTokenExpirationDate' => $doc['adminTokenExpirationDate'] ?? null,
            'allowSelfRegistration' => $doc['allowSelfRegistration'] ?? false,
            'selfRegistrationCode' => $doc['selfRegistrationCode'] ?? null,
        ]);
        $challenge->revision = $doc['_rev'] ?? null;

        return $challenge;
    }

    public function getCars(): array
    {
        return $this->cars;
    }

    public function getVoters(): array
    {
        return $this->voters;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function setRevisionNumber(string $rev)
    {
        $this->revision = $rev;
    }

    public function activate()
    {
        $this->isActive = true;
    }

    public function deactivate()
    {
        $this->isActive = false;
    }

    public function hasVoter($voterId): bool
    {
        return in_array($voterId, $this->voters);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAdminToken(): ?string
    {
        if ((new \DateTime())->getTimestamp() > $this->getAdminTokenExpirationDate()) {
            $this->adminToken = null;
        }
        return $this->adminToken;
    }

    public function generateAdminToken(): void
    {
        $token = Uuid::v4()->jsonSerialize();
        $this->adminToken = $token;
        $endTime = (new \DateTime())->add(new DateInterval('PT10M'));
        $this->adminTokenExpirationDate = $endTime->getTimestamp();
    }

    public function allowsSelfRegistration(): bool
    {
        return $this->allowSelfRegistration;
    }

    private function getAdminTokenExpirationDate(): ?int
    {
        return $this->adminTokenExpirationDate;
    }

    public function getSelfRegistrationCode(): ?string
    {
        return $this->selfRegistrationCode;
    }

    public function toggleSelfRegistration(): string
    {
        $this->allowSelfRegistration = !$this->allowSelfRegistration;
        $code = Uuid::v4()->jsonSerialize();
        $this->selfRegistrationCode = $code;
        return $code;
    }
}
