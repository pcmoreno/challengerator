<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

class RoundOfComparisons
{
    private array $challengeRounds;
    private string $id;

    public function __construct()
    {
        $this->challengeRounds = [];
    }

    public function getCarsLeftToVoteForChallenge($challengeId): array
    {
        if (isset($this->challengeRounds[$challengeId]['carsToVote'])) {
            return $this->challengeRounds[$challengeId]['carsToVote'];
        }
        return [];
    }

    public function setCarsToBeVotedForChallenge(array $carsLeftToVote, $challengeId): void
    {
        $this->challengeRounds[$challengeId]['carsToVote'] = array_values($carsLeftToVote);
    }

    public function getCarsComparedForChallenge($challengeId): array
    {
        if (isset($this->challengeRounds[$challengeId]['carsCompared'])) {
            return $this->challengeRounds[$challengeId]['carsCompared'];
        }
        return [];
    }

    public function setCarsAlreadyComparedForChallenge(array $carsCompared, $challengeId): void
    {
        $this->challengeRounds[$challengeId]['carsCompared'] = $carsCompared;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getForChallenge(string $challengeId)
    {
        return $this->challengeRounds[$challengeId];
    }

    public function has(string $challengeId): bool
    {
        return in_array($challengeId, array_keys($this->challengeRounds));
    }

    public function toArray(): array
    {
        return $this->challengeRounds;
    }

    public function moveCarFromNotVotedToComparedForChallenge($id, $challengeId): void
    {
        $leftToVote = $this->getCarsLeftToVoteForChallenge($challengeId);
        if (in_array($id, $leftToVote)) {
            $key = array_search($id, $leftToVote);
            unset($leftToVote[$key]);
            $this->setCarsToBeVotedForChallenge($leftToVote, $challengeId);

            $this->setCarsAlreadyComparedForChallenge(array_merge($this->getCarsComparedForChallenge($challengeId), [$id]), $challengeId);
        }
    }
}