<?php
declare(strict_types=1);

namespace App\Services;

use App\Entity\Auth\Role;
use App\Entity\Challenge\Car;
use App\Entity\Challenge\Challenge;
use App\Entity\Challenge\Outcome;
use App\Entity\Challenge\Voter;
use App\Repository\CarRepositoryInterface;
use App\Repository\ChallengeRepositoryInterface;
use App\Repository\InviteCodeRepositoryInterface;
use App\Repository\VoterRepositoryInterface;
use Psr\Log\LoggerInterface;
class ChallengeService
{
    public function __construct(
        private readonly ChallengeRepositoryInterface $challengeRepository,
        private readonly CarRepositoryInterface $carRepository,
        private readonly VoterRepositoryInterface $voterRepository,
        private readonly InviteCodeRepositoryInterface $inviteCodeRepository,
        private readonly LoggerInterface $votesLogger,
        private readonly LoggerInterface $loginsLogger,
        private readonly LoggerInterface $generalAppLogger,
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
        $this->carRepository->save($car);
        $challenge = $this->challengeRepository->find($challengeName);
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
        $saved = $this->voterRepository->findByName($voterName);
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

        $selectedCarIds = [];
        while (count($selectedCarIds) < 2) {
            $random = rand(0, count($carsToVote) - 1);
            $selectedCarIds[] = $carsToVote[$random];
            array_splice($carsToVote, $random, 1);
        }

        $selectedCars = $this->carRepository->findMany($selectedCarIds);

        return [$selectedCars, $carsToVote];
    }

    public function listChallenges(): array
    {
        return $this->challengeRepository->listNames();
    }

    public function voteOnCars(string $cars, string $result, string $challengeId, string $userId): array
    {
        $outcome = Outcome::tryFrom($result) ?? throw new \InvalidArgumentException('Wrong Result Chosen: ' . $result);
        $carIds = explode('XXX', $cars);

        $voter = $this->voterRepository->find($userId);
        $unvotedCars = $voter->getUnvotedCarsForChallenge($challengeId);

        foreach ($carIds as $carId) {
            if (!in_array($carId, $unvotedCars)) {
                throw new \DomainException('Car already voted');
            }
        }

        $voter->setCarsToVotedForChallenge($carIds, $challengeId);

        [$carA, $carB] = $this->carRepository->findMany($carIds);

        if ($carA->getChallengeId() !== $challengeId || $carB->getChallengeId() !== $challengeId) {
            throw new \InvalidArgumentException('Car does not belong to this challenge');
        }

        $ratingA = $carA->getRating();
        $ratingB = $carB->getRating();
        RatingService::compareAndAdjust($ratingA, $ratingB, $outcome);

        $this->carRepository->save($carA);
        $this->carRepository->save($carB);
        $this->voterRepository->save($voter);

        $logger->notice("Voting received on Challenge: " . $challengeId);
        $logger->notice($voter->getName() . " voted -- " . $result . " -- between " . $carA->getName() . " and " . $carB->getName());
        $logger->close();

        return $this->getTwoCarsToBeVotedByUser($challengeId, $userId);
    }

    public function verifyLogin(\stdClass $login, string $challengeName): array
    {
        $this->loginsLogger->notice($login->user . " is trying to login to " . $challengeName);

        $token = null;
        $challenge = null;

        if ($challengeName !== 'reset password') {
            $challenge = $this->challengeRepository->find($challengeName);
            if ($login->user === Role::ADMIN && $login->pass === $challenge->getOwner()) {
                $token = $this->doLoginForAdmin($challenge);
                $logger->notice(Role::ADMIN);
                return [Role::ADMIN, null, $token];
            }
        }

        $voter = $this->voterRepository->findByName($login->user);
        if ($voter !== null && password_verify($login->pass, $voter->getAuthKey())) {
            $token = $this->doLoginForUser($voter);
            if ($challenge !== null && $challenge->hasVoter($voter->getId())) {
                $logger->notice(Role::VOTER);
                return [Role::VOTER, $voter->getId(), $token];
            }
            $logger->notice(Role::VOTER_OF_A_DIFFERENT_CHALLENGE);
            return [Role::VOTER_OF_A_DIFFERENT_CHALLENGE, $voter->getId(), $token];
        }

        $logger->notice('failed');
        return [Role::NONE, null, $token];
    }

    public function verifyAdmin(string $challengeName, string $adminpass): bool
    {
        $challenge = $this->challengeRepository->find($challengeName);
        return password_verify($adminpass, $challenge->getOwner());
    }

    public function isVoterTokenValid(string $tokenShown, string $voterId): bool
    {
        $voter = $this->voterRepository->find($voterId);
        return $voter !== null && $voter->getToken() !== null && $voter->getToken() === $tokenShown;
    }

    public function isAdminTokenValid(string $tokenShown, string $challengeName): bool
    {
        $challenge = $this->challengeRepository->find($challengeName);
        return $challenge->getAdminToken() !== null && $challenge->getAdminToken() === $tokenShown;
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
            $logger->notice("success");
            return true;
        } catch (\Exception $exception) {
            $logger->alert($exception->getMessage());
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
        $challenge = $this->challengeRepository->find($challengeName);
        $challenge->addVoterToChallenge($voter);
        $this->challengeRepository->save($challenge);
    }

    private function doLoginForUser(Voter $voter): string
    {
        $voter->generateToken();
        $this->voterRepository->save($voter);
        return $voter->getToken();
    }

    private function doLoginForAdmin(Challenge $challenge): string
    {
        $challenge->generateAdminToken();
        $this->challengeRepository->save($challenge);
        return $challenge->getAdminToken();
    }

}
