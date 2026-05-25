<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

use Symfony\Component\Uid\Uuid;

class Challenge
{
    private string $id;
    private string $name;
    private string $displayName;
    private array $cars;
    private array $voters;
    private bool $isActive;
    private string $owner;
    private bool $allowSelfRegistration;
    private ?string $selfRegistrationCode;

    private function __construct(string $id, string $name, string $displayName, array $cars, array $voters, bool $isActive, string $owner)
    {
        $this->id = $id;
        $this->name = $name;
        $this->displayName = $displayName !== '' ? $displayName : $name;
        $this->cars = $cars;
        $this->voters = $voters;
        $this->isActive = $isActive;
        $this->owner = $owner;
        $this->allowSelfRegistration = false;
        $this->selfRegistrationCode = null;
    }

    public static function create(string $name, string $displayName, string $owner): Challenge
    {
        return new Challenge('info', $name, $displayName, [], [], false, $owner);
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

    public static function fromArray(array $doc): Challenge
    {
        $challenge = new Challenge(
            $doc['id'],
            $doc['name'],
            $doc['displayName'] ?? $doc['name'],
            $doc['cars'],
            $doc['voters'],
            $doc['isActive'],
            $doc['owner']
        );
        $challenge->allowSelfRegistration = $doc['allowSelfRegistration'] ?? false;
        $challenge->selfRegistrationCode = $doc['selfRegistrationCode'] ?? null;

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

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function allowsSelfRegistration(): bool
    {
        return $this->allowSelfRegistration;
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
