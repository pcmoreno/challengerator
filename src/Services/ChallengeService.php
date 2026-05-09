<?php
declare(strict_types=1);

namespace App\Services;

use App\Entity\Auth\Role;
use App\Entity\Challenge\Car;
use App\Entity\Challenge\Challenge;
use App\Entity\Challenge\Voter;
use App\Repository\CarRepositoryInterface;
use App\Repository\ChallengeRepositoryInterface;
use App\Repository\InviteCodeRepositoryInterface;
use App\Repository\VoterRepositoryInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\JsonResponse;

class ChallengeService
{
    private const LOGIN_LOG_PATH = 'logs/logins.log';
    private const VOTING_LOG_PATH = 'logs/votes.log';
    private const GENERAL_LOG_PATH = 'logs/general.log';
    private array $loggers;

    public function __construct(
        private readonly ChallengeRepositoryInterface $challengeRepository,
        private readonly CarRepositoryInterface $carRepository,
        private readonly VoterRepositoryInterface $voterRepository,
        private readonly InviteCodeRepositoryInterface $inviteCodeRepository,
    ) {
        $this->initializeLoggers();
    }

    public function createNewChallenge(string $name, string $owner, string $code): JsonResponse
    {
        if (!$this->inviteCodeRepository->validateAndConsume($code)) {
            return new JsonResponse('Code not valid', JsonResponse::HTTP_FORBIDDEN);
        }
        try {
            $this->challengeRepository->create($name);
            $challenge = Challenge::create($name, $owner);
            $this->challengeRepository->save($challenge);
            return new JsonResponse('added: ' . $name);
        } catch (\Exception $exception) {
            return new JsonResponse($exception->getMessage(), 400);
        }
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
        $this->resetRoundOfVoteForUserOfChallenge($challengeName, $voter->getId());
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

    public function initializeChallenge(string $challengeName): JsonResponse
    {
        $challenge = $this->challengeRepository->find($challengeName);
        $carIds = $challenge->getCars();

        foreach ($this->voterRepository->findMany($challenge->getVoters()) as $voter) {
            $voter->addCarsToSelf($carIds, $challengeName, true);
            $this->voterRepository->save($voter);
        }

        $challenge->activate();
        $this->challengeRepository->save($challenge);

        return new JsonResponse('initialization done', 200);
    }

    public function resetRoundOfVoteForUserOfChallenge(string $challengeName, string $voterId): JsonResponse
    {
        $challenge = $this->challengeRepository->find($challengeName);
        if (!$challenge->hasVoter($voterId)) {
            return new JsonResponse('this voter is not part of this challenge', 400);
        }
        $voter = $this->voterRepository->find($voterId);
        $voter->addCarsToSelf($challenge->getCars(), $challengeName, true);
        $this->voterRepository->save($voter);

        return new JsonResponse('success', 200);
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

    public function listChallenges(): JsonResponse
    {
        return new JsonResponse($this->challengeRepository->listNames());
    }

    public function voteOnCars(string $cars, string $result, string $challengeId, string $userId): array
    {
        if (!in_array($result, ['left', 'right', 'draw'])) {
            throw new \Exception('Wrong Result Chosen: ' . $result, 400);
        }
        $logger = $this->getLogger('voters');
        $carIds = explode('XXX', $cars);

        $voter = $this->voterRepository->find($userId);
        $unvotedCars = $voter->getUnvotedCarsForChallenge($challengeId);

        foreach ($carIds as $carId) {
            if (!in_array($carId, $unvotedCars)) {
                throw new \Exception('Car already voted', JsonResponse::HTTP_CONFLICT);
            }
        }

        $voter->setCarsToVotedForChallenge($carIds, $challengeId);

        [$carA, $carB] = $this->carRepository->findMany($carIds);
        $ratingA = $carA->getRating();
        $ratingB = $carB->getRating();
        RatingService::compareAndAdjust($ratingA, $ratingB, $result);

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
        $logger = $this->getLogger('users');
        $logger->notice($login->user . " is trying to login to " . $challengeName);

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
        return $adminpass === $challenge->getOwner();
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
        $logger = $this->getLogger('users');
        try {
            $logger->notice($voterName . " is resetting password");
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

    private function getLogger(string $whichOne): Logger
    {
        return $this->loggers[$whichOne];
    }

    private function initializeLoggers(): void
    {
        $voteLogger = new Logger('voters');
        $voteLogger->pushHandler(new StreamHandler(self::VOTING_LOG_PATH, Logger::NOTICE));
        $this->loggers['voters'] = $voteLogger;

        $loginLogger = new Logger('users');
        $loginLogger->pushHandler(new StreamHandler(self::LOGIN_LOG_PATH, Logger::NOTICE));
        $this->loggers['users'] = $loginLogger;

        $generalLogger = new Logger('general');
        $generalLogger->pushHandler(new StreamHandler(self::GENERAL_LOG_PATH, Logger::NOTICE));
        $this->loggers['general'] = $generalLogger;
    }
}
