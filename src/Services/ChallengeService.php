<?php
declare(strict_types=1);

namespace App\Services;

use App\Entity\Challenge\Car;
use App\Entity\Challenge\Challenge;
use App\Entity\Challenge\Outcome;
use App\Entity\Challenge\Voter;
use App\Exception\BusinessLogicException;
use App\Repository\CarRepositoryInterface;
use App\Repository\ChallengeRepositoryInterface;
use App\Repository\InviteCodeRepositoryInterface;
use App\Repository\TransactionInterface;
use App\Repository\VoterRepositoryInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;

class ChallengeService
{
    public function __construct(
        private readonly ChallengeRepositoryInterface $challengeRepository,
        private readonly CarRepositoryInterface $carRepository,
        private readonly VoterRepositoryInterface $voterRepository,
        private readonly InviteCodeRepositoryInterface $inviteCodeRepository,
        private readonly TransactionInterface $transaction,
        private readonly LoggerInterface $votesLogger,
        private readonly LoggerInterface $loginsLogger,
    ) {}

    public function createNewChallenge(string $name, string $owner, string $code): void
    {
        if (!$this->inviteCodeRepository->validateAndConsume($code)) {
            throw new \DomainException('Code not valid');
        }
        $hashed = password_hash($owner, PASSWORD_BCRYPT);
        if ($hashed === false) {
            throw new \RuntimeException('Failed to hash admin password');
        }
        $this->challengeRepository->create($name);
        $challenge = Challenge::create($name, $hashed);
        $this->challengeRepository->save($challenge);
    }

    public function addCar(string $challengeName, Car $car): void
    {
        $car->setChallengeId($challengeName);
        $challenge = $this->challengeRepository->find($challengeName);
        $this->carRepository->save($car);
        $challenge->addCarToChallenge($car);
        $this->challengeRepository->save($challenge);
    }

    public function AddVoterToChallengeFromIp(string $challengeName, string $voterName, string $password, string $ip): bool
    {
        $challenge = $this->challengeRepository->find($challengeName);
        if (!$challenge->allowsSelfRegistration()) {
            return false;
        }
        if ($this->voterRepository->hasVoterFromIpForChallenge($ip, $challengeName)) {
            return false;
        }
        $voter = Voter::createForChallenge($voterName, $password, $challengeName, $ip);
        $this->addVoterToChallenge($challengeName, $voter);
        $saved = $this->voterRepository->findByName($voterName)
            ?? throw new \RuntimeException('Voter not found after save: ' . $voterName);
        $this->resetRoundOfVoteForUserOfChallenge($challengeName, $saved->getId());
        return true;
    }

    public function addVoter(string $challengeName, Voter $voter): void
    {
        $this->addVoterToChallenge($challengeName, $voter);
    }

    public function deleteVoterFromChallenge(string $challengeName, string $voterId): void
    {
        $this->voterRepository->delete($voterId);
        $challenge = $this->challengeRepository->find($challengeName);
        $challenge->removeVoterFromChallenge($voterId);
        $this->challengeRepository->save($challenge);
    }

    public function deleteCarFromChallenge(string $challengeName, string $carId): void
    {
        $this->carRepository->delete($carId);
        $challenge = $this->challengeRepository->find($challengeName);
        $challenge->removeCarFromChallenge($carId);
        $this->challengeRepository->save($challenge);
    }

    public function getCarsForChallenge(string $challengeName): array
    {
        $challenge = $this->challengeRepository->find($challengeName);
        return $this->carRepository->findMany($challenge->getCars());
    }

    public function getAllVotersForTheChallenge(string $challengeName): array
    {
        $challenge = $this->challengeRepository->find($challengeName);
        return $this->voterRepository->findMany($challenge->getVoters());
    }

    public function initializeChallenge(string $challengeName): void
    {
        $challenge = $this->challengeRepository->find($challengeName);
        $carIds = $challenge->getCars();

        foreach ($this->voterRepository->findMany($challenge->getVoters()) as $voter) {
            $voter->addCarsToSelf($carIds, $challengeName);
            $this->voterRepository->save($voter);
        }

        $challenge->activate();
        $this->challengeRepository->save($challenge);
    }

    public function resetRoundOfVoteForUserOfChallenge(string $challengeName, string $voterId): void
    {
        $challenge = $this->challengeRepository->find($challengeName);
        if (!$challenge->hasVoter($voterId)) {
            throw new \DomainException('This voter is not part of this challenge');
        }
        $voter = $this->voterRepository->find($voterId);
        $voter->addCarsToSelf($challenge->getCars(), $challengeName);
        $this->voterRepository->save($voter);
    }

    public function getTwoCarsToBeVotedByUser(string $challengeName, string $userId): array
    {
        $voter = $this->voterRepository->find($userId);
        $carsToVote = $voter->getUnvotedCarsForChallenge($challengeName);

        if (count($carsToVote) < 2) {
            return [[], []];
        }

        $selectedIndices = array_rand($carsToVote, 2);
        $selectedCarIds = [$carsToVote[$selectedIndices[0]], $carsToVote[$selectedIndices[1]]];
        $remaining = array_values(array_diff_key($carsToVote, array_flip($selectedIndices)));

        $selectedCars = $this->carRepository->findMany($selectedCarIds);

        return [$selectedCars, $remaining];
    }

    public function listChallenges(): array
    {
        return $this->challengeRepository->listNames();
    }

    public function voteOnCars(string $cars, string $result, string $challengeId, string $userId): array
    {
        $outcome = Outcome::tryFrom($result) ?? throw new \InvalidArgumentException('Wrong Result Chosen: ' . $result);
        $carIds = explode('XXX', $cars);

        if (count($carIds) !== 2 || $carIds[0] === $carIds[1]) {
            throw new BusinessLogicException('Pair must contain two distinct cars');
        }

        $voter = $this->voterRepository->find($userId);
        $unvotedCars = $voter->getUnvotedCarsForChallenge($challengeId);

        foreach ($carIds as $carId) {
            if (!in_array($carId, $unvotedCars)) {
                throw new \DomainException('Car already voted');
            }
        }

        [$carA, $carB] = $this->carRepository->findMany($carIds);

        if ($carA->getChallengeId() !== $challengeId || $carB->getChallengeId() !== $challengeId) {
            throw new \InvalidArgumentException('Car does not belong to this challenge');
        }

        $voterName = $voter->getName();
        $carAName  = $carA->getName();
        $carBName  = $carB->getName();

        $next = null;
        $lastException = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $next = $this->transaction->transactional(function () use (
                    $voter, $carIds, $challengeId, $carA, $carB, $outcome, $userId
                ): array {
                    $voter->setCarsToVotedForChallenge($carIds, $challengeId);

                    $ratingA = $carA->getRating();
                    $ratingB = $carB->getRating();
                    RatingService::compareAndAdjust($ratingA, $ratingB, $outcome);

                    $this->carRepository->save($carA);
                    $this->carRepository->save($carB);
                    $this->voterRepository->save($voter);

                    return $this->getTwoCarsToBeVotedByUser($challengeId, $userId);
                });
                break;
            } catch (OptimisticLockException $e) {
                $lastException = $e;
                [$carA, $carB] = $this->carRepository->findMany($carIds);
                $voter = $this->voterRepository->find($userId);
            }
        }

        if ($next === null) {
            throw new BusinessLogicException('Vote conflict after retries; please try again', 0, $lastException);
        }

        $this->votesLogger->notice("Voting received on Challenge: " . $challengeId);
        $this->votesLogger->notice($voterName . " voted -- " . $result . " -- between " . $carAName . " and " . $carBName);

        return $next;
    }

    public function verifyAdmin(string $challengeName, string $adminpass): bool
    {
        $challenge = $this->challengeRepository->find($challengeName);
        return password_verify($adminpass, $challenge->getOwner());
    }

    public function verifyVoterCredentials(string $username, string $password): bool
    {
        $voter = $this->voterRepository->findByName($username);
        return $voter !== null && password_verify($password, $voter->getAuthKey());
    }

    public function changePassForVoter(string $voterName, string $newPass): bool
    {
        try {
            $this->loginsLogger->notice($voterName . " is resetting password");
            $voter = $this->voterRepository->findByName($voterName);
            if ($voter === null) {
                throw new \Exception('Voter not found');
            }
            $hashed = password_hash($newPass, PASSWORD_BCRYPT);
            if ($hashed === false || $hashed === null) {
                throw new \Exception('Failed hashing password');
            }
            $voter->setAuthKey($hashed);
            $this->voterRepository->save($voter);
            $this->loginsLogger->notice("success");
            return true;
        } catch (\Exception $exception) {
            $this->loginsLogger->alert($exception->getMessage());
            return false;
        }
    }

    public function isChallengeOpenToSelfRegistration(string $challengeName): string
    {
        $challenge = $this->challengeRepository->find($challengeName);
        return $challenge->allowsSelfRegistration() ? 'active' : 'inactive';
    }

    public function toggleSelfRegistrationForChallenge(string $challengeName): string|false
    {
        try {
            $challenge = $this->challengeRepository->find($challengeName);
            $code = $challenge->toggleSelfRegistration();
            $this->challengeRepository->save($challenge);
            return $code;
        } catch (\Exception $exception) {
            return false;
        }
    }

    public function getSelfRegistrationCodeForChallenge(string $challengeName): ?string
    {
        return $this->challengeRepository->find($challengeName)->getSelfRegistrationCode();
    }

    public function isTheSelfRegistrationCodeCorrect(string $challengeName, string $selfRegistrationCode): bool
    {
        return $selfRegistrationCode === $this->challengeRepository->find($challengeName)->getSelfRegistrationCode();
    }

    private function addVoterToChallenge(string $challengeName, Voter $voter): void
    {
        $this->voterRepository->save($voter);
        $persisted = $this->voterRepository->findByName($voter->getName()) ?? $voter;
        $challenge = $this->challengeRepository->find($challengeName);
        $challenge->addVoterToChallenge($persisted);
        $this->challengeRepository->save($challenge);
    }

}
