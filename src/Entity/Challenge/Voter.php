<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

use Symfony\Component\Uid\Uuid;

class Voter
{
    private string $id;
    private string $name;
    private RoundOfComparisons $roundsOfComparison;
    private string $authKey;
    private ?string $token;

    public static function createForChallenge(string $name, string $pass, string $challengeId): Voter
    {
        $voter = new Voter();
        $voter->id = Uuid::v6()->jsonSerialize();
        $voter->name = $name;
        $hashed_password = password_hash($pass, PASSWORD_BCRYPT);
        if ($hashed_password === false || $hashed_password === null) {
            throw new \Exception('Failed Hashing Password, creation of Voter aborted');
        }
        $voter->authKey = $hashed_password;
        $round = new RoundOfComparisons(); // TODO: not great looking
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

    // TODO:
    // needs to get a token on login

    public function toCouchDocument(): \stdClass
    {
        $stdClass = new \stdClass();
        $stdClass->_id = $this->id;
        $stdClass->name = $this->name;
        $stdClass->challenges = $this->roundsOfComparison->toArray();
        $stdClass->key = $this->authKey;
        $stdClass->token = isset($this->token)? $this->token : null;
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
}