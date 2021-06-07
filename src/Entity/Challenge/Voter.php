<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

use DateInterval;
use Symfony\Component\Uid\Uuid;

class Voter
{
    private string $id;
    private string $name;
    private RoundOfComparisons $roundsOfComparison;
    private string $authKey;
    private ?string $token;
    private ?int $tokenExpirationDate;

    public static function createForChallenge(string $name, string $pass, string $challengeId): Voter
    {
        $voter = new Voter();
        $voter->id = Uuid::v6()->jsonSerialize();
        $voter->name = $name;
        $voter->tokenExpirationDate = null;
        $voter->token = null;
        $hashed_password = password_hash($pass, PASSWORD_BCRYPT);
        if ($hashed_password === false || $hashed_password === null) {
            throw new \Exception('Failed Hashing Password, creation of Voter aborted');
        }
        $voter->authKey = $hashed_password;
        $round = new RoundOfComparisons();
        $round->setCarsToBeVotedForChallenge([], $challengeId);
        $round->setCarsAlreadyComparedForChallenge([], $challengeId);

        $voter->roundsOfComparison = $round;

        return $voter;
    }

    //TODO: add for challenge function

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function toCouchDocument(): \stdClass
    {
        $stdClass = new \stdClass();
        $stdClass->_id = $this->id;
        $stdClass->name = $this->name;
        $stdClass->challenges = $this->roundsOfComparison->toArray();
        $stdClass->key = $this->authKey;
        $stdClass->token = isset($this->token)? $this->token : null;
        $stdClass->tokenExpirationDate = isset($this->tokenExpirationDate)? $this->tokenExpirationDate : null;
        return $stdClass;
    }

    public static function fromCouchDocument($data): self
    {
        $data = json_decode(json_encode($data), true);
        $voter = new Voter();
        $voter->id = $data['_id'];
        $voter->name = $data['name'];
        $roundsOfComparisons = new RoundOfComparisons();
        foreach ($data['challenges'] as $id => $challenge) {
            $roundsOfComparisons->setCarsToBeVotedForChallenge(
                isset($challenge['carsToVote']) ? $challenge['carsToVote'] : [],
                $id
            );
            $roundsOfComparisons->setCarsAlreadyComparedForChallenge(
                isset($challenge['carsCompared']) ? $challenge['carsCompared'] : [],
                $id
            );
        }
        $voter->roundsOfComparison = $roundsOfComparisons;
        $voter->authKey = $data['key'];
        $voter->token = $data['token'];
        $voter->tokenExpirationDate = $data['tokenExpirationDate'] ?? null;
        return $voter;
    }

    public function addCarsToSelf(array $cars, string $challengeId, bool $clean = false): void
    {
        if ($this->roundsOfComparison->has($challengeId)) {
            /** @var RoundOfComparisons $roundOfComparisons */
            $roundOfComparisons = $this->roundsOfComparison;
            if ($clean) {
                $roundOfComparisons->setCarsToBeVotedForChallenge($cars, $challengeId);
                $roundOfComparisons->setCarsAlreadyComparedForChallenge([], $challengeId);
            } else { //TODO
                $carsLeftToVote = $roundOfComparisons->getCarsLeftToVote();
                $roundOfComparisons->setCarsLeftToVote(array_merge($cars, $carsLeftToVote));
            }
        } else {
            throw new \Exception('Voter not registered with challenge', 400);
        }
        $this->roundsOfComparison = $roundOfComparisons;
    }

    public function getVotedCarsForChallenge($challengeId)
    {
        return $this->roundsOfComparison->getCarsComparedForChallenge($challengeId);
    }

    public function getUnvotedCarsForChallenge($challengeId)
    {
        return $this->roundsOfComparison->getCarsLeftToVoteForChallenge($challengeId);
    }

    public function setCarsToVotedForChallenge($carIds, string $challengeId): void
    {
        if (!$this->roundsOfComparison->has($challengeId)) {
            throw new \Exception('Voter not registered with challenge', 400);
        }
        foreach ($carIds as $carId) {
           $this->roundsOfComparison->moveCarFromNotVotedToComparedForChallenge($carId, $challengeId);
        }
    }

    public function getAuthKey(): string
    {
        return $this->authKey;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setAuthKey(string $authKey): void
    {
        $this->authKey = $authKey;
    }

    public function generateToken(): void
    {
        $token = Uuid::v4()->jsonSerialize();
        $this->token = $token;
        $endTime = (new \DateTime())->add(new DateInterval('PT20M'));
        $this->tokenExpirationDate = $endTime->getTimestamp();
    }

    public function getTokenExpirationDate(): ?int
    {
        return $this->tokenExpirationDate;
    }

    public function getToken(): ?string
    {
        if ((new \DateTime())->getTimestamp() > $this->getTokenExpirationDate()) {
            $this->token = null;
        }
        return $this->token;
    }

    public function countComparisonsMadeForChallenge($challengeName): int
    {
        $total = $this->roundsOfComparison->getCarsComparedForChallenge($challengeName);
        return (count($total))/2;
    }

}
