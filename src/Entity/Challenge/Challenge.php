<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

class Challenge
{
    private string $id;
    private string $name;
    private array $cars;
    private array $voters;
    private bool $isActive;
    private string $owner;
    private ?string $revision;

    private function __construct(string $id, string $name, array $cars, array $voters, bool $isActive, string $owner)
    {
        $this->id = $id;
        $this->name = $name;
        $this->cars = $cars;
        $this->voters = $voters;
        $this->isActive = $isActive;
        $this->owner = $owner;
        $this->revision = null;
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
        return $stdclass;
    }

    public static function fromCouchDocument(array $doc): Challenge
    {
        return new Challenge(
          $doc['_id'],
          $doc['name'],
          $doc['cars'],
          $doc['voters'],
          $doc['isActive'],
          $doc['owner'],
        );
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

}