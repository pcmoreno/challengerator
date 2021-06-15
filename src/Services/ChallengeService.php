<?php
declare(strict_types=1);

namespace App\Services;

use App\Entity\Auth\Role;
use App\Entity\Challenge\Car;
use App\Entity\Challenge\Challenge;
use App\Entity\Challenge\Voter;
use Monolog\Handler\StreamHandler;
use PHPOnCouch\CouchClient;
use PHPOnCouch\Exceptions\CouchNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Monolog\Logger;

class ChallengeService
{
    private const LOGIN_LOG_PATH = 'logs/logins.log';
    private const VOTING_LOG_PATH = 'logs/votes.log';
    private CouchDbService $service;
    private CouchClient $client;
    private string $dsn;

    public function __construct(
        CouchDbService $service,
        string $dsn
    ) {
        $this->service = $service;
        $this->dsn = $dsn;
    }

    public function createNewChallenge(string $name, string $owner, string $code): JsonResponse
    {
        $codeService = new CodeService($this->dsn);
        $success = $codeService->validateAndConsumeCode($code);
        if (!$success) {
            return new JsonResponse('Code not valid', JsonResponse::HTTP_FORBIDDEN);
        }

        $this->client = $this->getCouchClient($name);
        try {
            $this->client->createDatabase();
        } catch (\Exception $exception) {
            return new JsonResponse($exception->getMessage(), 400);
        }
        $challenge = Challenge::create($name, $owner);

        $infoDoc = $challenge->toCouchDocument();

        try {
            $this->client->storeDoc($infoDoc);
            return new JsonResponse('added: ' . $name);
        } catch (\Exception $exception) {
            return new JsonResponse(
                ['error' => $exception->getMessage()]
            );
        }
    }

    public function addCarFromDataArray(string $challengeName, array $carData): JsonResponse
    {
        try {
            $car = Car::create($carData);
        } catch (\Exception $exception) {
            return new JsonResponse($exception->getMessage(), 400);
        }
        $this->addCar($challengeName, $car);

        return new JsonResponse('success', 201);
    }

    public function addCar(string $challengeName, Car $car, string $token): void
    {
        if ($this->isAdminTokenValid($token, $challengeName)) {
            $carClient = $this->getCouchClient('cars');
            $challengeClient = $this->getCouchClient($challengeName);

            $carClient->storeDoc($car->toCouchDocument());

            $data = json_decode(json_encode($challengeClient->getDoc('info')), true);
            $challenge = Challenge::fromCouchDocument($data);
            $challenge->addCarToChallenge($car);
            $challenge->setRevisionNumber($data['_rev']);
            $challengeClient->storeDoc($challenge->toCouchDocument());
        }
    }

    public function AddVoterToChallengeFromIp($challengeName, $voterName, $password): bool
    {
        $challengeClient = $this->getCouchClient($challengeName);
        $challengeDoc = $challengeClient->getDoc('info');
        if (isset($challengeDoc->allowsSelfRegistration) && $challengeDoc->allowsSelfRegistration === false) {
            return false;
        }
        $ip = $_SERVER['REMOTE_ADDR'];
        $voterClient = $this->getCouchClient('voters');
        $selector = ['ipAddress' => ['$eq' => $ip]];
        $voterDoc = $voterClient->find($selector);

        $existingVotersWithSameIpAndChallenge = array_filter(json_decode(json_encode($voterDoc), true), function ($item) use ($challengeName) {
            return array_key_exists($challengeName, $item['challenges']);
        });

        if (count($existingVotersWithSameIpAndChallenge) === 0) {
            $voter = Voter::createForChallenge($voterName, $password, $challengeName, $ip);
            $this->addVoterToChallenge($challengeName, $voter);

            // immediately allow this user to cast votes
            $this->resetRoundOfVoteForUserOfChallenge($challengeName, $voter->getId());
            return true;
        }
        return false;
    }

    public function addVoter(string $challengeName, Voter $voter, string $token): void
    {
        if (!$this->isAdminTokenValid($token, $challengeName)) {
            return;
        }
        $this->addVoterToChallenge($challengeName, $voter);
    }

    public function deleteVoterFromChallenge(string $challengeName, string $voterId): void
    {
        $voterClient = $this->getCouchClient('voters');
        $challengeClient = $this->getCouchClient($challengeName);

        $voterClient->deleteDoc($voterClient->getDoc($voterId));

        $data = json_decode(json_encode($challengeClient->getDoc('info')), true);
        $challenge = Challenge::fromCouchDocument($data);

        $challenge->removeVoterFromChallenge($voterId);
        $challenge->setRevisionNumber($data['_rev']);
        $challengeClient->storeDoc($challenge->toCouchDocument());
    }

    public function deleteCarFromChallenge(string $challengeName, string $carId): void
    {
        $carClient = $this->getCouchClient('cars');
        $challengeClient = $this->getCouchClient($challengeName);

        $carClient->deleteDoc($carClient->getDoc($carId));

        $data = json_decode(json_encode($challengeClient->getDoc('info')), true);
        $challenge = Challenge::fromCouchDocument($data);

        $challenge->removeCarFromChallenge($carId);
        $challenge->setRevisionNumber($data['_rev']);
        $challengeClient->storeDoc($challenge->toCouchDocument());
    }

    public function getCarsForChallenge(string $challengeName, bool $json = true)
    {
        $carClient = $this->getCouchClient('cars');
        $challengeClient = $this->getCouchClient($challengeName);

        $challengeData = json_decode(json_encode($challengeClient->getDoc('info')), true);
        /** @var Challenge $challenge */
        $challenge = Challenge::fromCouchDocument($challengeData);
        $challengeCarsIds = $challenge->getCars();

        $result = [];
        foreach ($challengeCarsIds as $challengeCarId) {
            $carDoc = $carClient->getDoc($challengeCarId);
            $result[$challengeCarId] = json_decode(json_encode($carDoc), true);
        }
        if ($json) {
            return new JsonResponse($result);
        } else {
            $cars = array_map(function ($item) {
                return Car::fromCouchData($item);
            },$result);
            return $cars;
        }
    }

    public function getAllVotersForTheChallenge(string $challengeName): array
    {
        $challengeClient = $this->getCouchClient($challengeName);
        $data = json_decode(json_encode($challengeClient->getDoc('info')), true);
        $challenge = Challenge::fromCouchDocument($data);
        $votersIds = $challenge->getVoters();
        $voterClient = $this->getCouchClient('voters');
        $voters = [];
        foreach ($votersIds as $voterId) {
            $voter = Voter::fromCouchDocument(json_decode(json_encode($voterClient->getDoc($voterId)), true));
            $voters[] = $voter;
        }

        return $voters;
    }

    // TODO: should do it to only do to non active challenges.
    public function initializeChallenge($challengeName): JsonResponse
    {
        $challengeClient = $this->getCouchClient($challengeName);
        $voterClient = $this->getCouchClient('voters');

        $challengeData = json_decode(json_encode($challengeClient->getDoc('info')), true);

        $voterIds = $challengeData['voters'];
        foreach ($voterIds as $voterId) {
            $voter = $this->getVoter($voterId);
            if ($voter !== null) {
                $voter->addCarsToSelf($challengeData['cars'], $challengeName, true);
                $voterDocRev = $voterClient->getDoc($voterId)->_rev;
                $voterDocToSave = $voter->toCouchDocument();
                $voterDocToSave->_rev = $voterDocRev;
                $voterClient->storeDoc($voterDocToSave);
            }
        }
        $challenge = Challenge::fromCouchDocument($challengeData);
        $challenge->activate();
        $challengeDocToUpdate = $challenge->toCouchDocument();
        $challengeDocToUpdate->_rev = $challengeData['_rev'];

        $challengeClient->storeDoc($challengeDocToUpdate);

        return new JsonResponse('initialization done', 200);
    }

    public function resetRoundOfVoteForUserOfChallenge($challengeName, $voterId)
    {
        $challengeClient = $this->getCouchClient($challengeName);
        $voterClient = $this->getCouchClient('voters');

        $challengeData = json_decode(json_encode($challengeClient->getDoc('info')), true);
        $challenge = Challenge::fromCouchDocument($challengeData);
        if ($challenge->hasVoter($voterId)) {
            $voter = $this->getVoter($voterId);
            $voter->addCarsToSelf($challengeData['cars'], $challengeName, true);
            $voterDocRev = $voterClient->getDoc($voterId)->_rev;
            $voterDocToSave = $voter->toCouchDocument();
            $voterDocToSave->_rev = $voterDocRev;
            $voterClient->storeDoc($voterDocToSave);

            return new JsonResponse('success' ,200);
        }
        return new JsonResponse('this voter is not part of this challenge', 400);
    }

    public function getTwoCarsToBeVotedByUser(string $challengeName, string $userId): array
    {
        $voter = $this->getVoter($userId);
        $carsToVote = $voter->getUnvotedCarsForChallenge($challengeName);

        if (count($carsToVote) < 2) {
            return [[],[]];
        }
        $selectedCarsForVote = [];
        while (count($selectedCarsForVote) < 2) {
            $random = rand(0, count($carsToVote) -1);
            $selectedCarsForVote[] = $carsToVote[$random];
            array_splice($carsToVote, $random, 1);
        }
        $dataToReturn = [];
        $carClient = $this->getCouchClient('cars');
        foreach ($selectedCarsForVote as $item) {
            $carDoc = $carClient->getDoc($item);
            $arrayOfCar = json_decode(json_encode($carDoc), true);
            $dataToReturn[] = $arrayOfCar;
        }

        return [$dataToReturn, $carsToVote];
    }

    public function listChallenges(): JsonResponse
    {
        $dbList = $this->service->getDatabaseList();
        $array = array_filter($dbList, function ($entry) {
            return !in_array($entry, ['cars', 'voters', '_users', '_replicator', '_global_changes', 'codes']);
        });
        return new JsonResponse(array_values($array));
    }

    public function voteOnCars(string $cars, $result, string $challengeId, string $userId)
    {
        if (!in_array($result, [0,1,0.5])) {
            return new JsonResponse('Wrong Result Chosen', 400);
        }
        $logger = new Logger("votes");
        $logger->pushHandler(new StreamHandler(self::VOTING_LOG_PATH, Logger::NOTICE));

        $carIds = explode('XXX', $cars);

        $voterClient = $this->getCouchClient('voters');

        $voterDoc = $voterClient->getDoc($userId);
        $voterArray = json_decode(json_encode($voterDoc), true);

        // check if cars are on the user list to be voted...
        foreach ($carIds as $carId) {
            if (!in_array($carId, $voterArray['challenges'][$challengeId]['carsToVote'])) {
                throw new \Exception('Car already voted', JsonResponse::HTTP_CONFLICT);
            }
        }
        $voter = Voter::fromCouchDocument($voterArray);

        // adjust the voter's cars voted/not voted
        $voter->setCarsToVotedForChallenge($carIds, $challengeId);

        // apply the rating changes to the cars
        $carClient = $this->getCouchClient('cars');
        $carsDocs = array_map(function (string $car) use ($carClient){
            $carDoc = $carClient->getDoc($car);
            return (json_decode(json_encode($carDoc), true));
        }, $carIds);

        $carA = Car::fromCouchData($carsDocs[0]);
        $carB = Car::fromCouchData($carsDocs[1]);
        $ratingA = $carA->getRating();
        $ratingB = $carB->getRating();
        dump($carA->getRating());
        RatingService::compareAndAdjust($ratingA, $ratingB, $result);
        dump($carA->getRating());
        dump($ratingB);
        $updatedVoterDoc = $voter->toCouchDocument();
        $updatedVoterDoc->_rev = $voterDoc->_rev;

        //persist updated cars
        $counter = 0;
        foreach ([$carA, $carB] as $updatedCar) {
            $updatedCarDoc = $updatedCar->toCouchDocument();
            $updatedCarDoc->_rev = $carsDocs[$counter]['_rev'];

            $carClient->storeDoc($updatedCarDoc);
            $counter++;
        }
        //persist updated voter
        $voterClient->storeDoc($updatedVoterDoc);
        $logger->notice("Voting received on Challenge: " . $challengeId);
        $logger->notice($voter->getName() . " voted -- " . $result . " -- between ". $carA->getName() . " and " . $carB->getName());

        $logger->close();

        return $this->getTwoCarsToBeVotedByUser($challengeId, $userId);
    }

    public function verifyLogin($login, $challengeName)
    {
        $logger = new Logger("users");
        $logger->pushHandler(new StreamHandler(self::LOGIN_LOG_PATH, Logger::NOTICE));
        $logger->notice($login->user . " with pass " . $login->pass . " is trying to login to " . $challengeName);
        $token = null;
        if ($challengeName !== 'reset password') {
            $challengeClient = $this->getCouchClient($challengeName);

            $challengeDoc = $challengeClient->getDoc('info');
            $challenge = Challenge::fromCouchDocument(json_decode(json_encode($challengeDoc), true));
            if ($login->user === Role::ADMIN && $login->pass === $challengeDoc->owner) {
                $token = $this->doLoginForAdmin($challenge);
                $logger->notice(Role::ADMIN);
                return [Role::ADMIN, null, $token];
            }
        }
        $voterClient = $this->getCouchClient('voters');
        try {
            $voterDoc = $voterClient->find([
                'name' => $login->user
            ]);
            if ($voterDoc !== [] && password_verify($login->pass, $voterDoc[0]->key)) {
                $voter = Voter::fromCouchDocument($voterDoc[0]);
                $token = $this->doLoginForUser($voter);
                if ($challengeName !== 'reset password' && $challenge->hasVoter($voterDoc[0]->_id)) {
                    $logger->notice(Role::VOTER);
                    return [Role::VOTER, $voterDoc[0]->_id, $token];
                } else {
                    $logger->notice(Role::VOTER_OF_A_DIFFERENT_CHALLENGE);
                    return [Role::VOTER_OF_A_DIFFERENT_CHALLENGE, $voterDoc[0]->_id, $token];
                }
            }
        } catch (\Exception $exception) {
            $logger->alert($exception->getMessage());
            dump($exception); die;
        }
        $logger->notice('failed');
        return [Role::NONE, null, $token];
    }

    public function verifyAdmin($challengeName, $adminpass): bool
    {
        $challengeClient = $this->getCouchClient($challengeName);

        $challengeDoc = $challengeClient->getDoc('info');
        return $adminpass === $challengeDoc->owner;
    }

    private function getVoter($id): ?Voter
    {
        $voterClient = $this->getCouchClient('voters');
        try {
            $voterDoc = $voterClient->getDoc($id);
            return Voter::fromCouchDocument(json_decode(json_encode($voterDoc), true));
        } catch (CouchNotFoundException $exception) {
            return null;
        }
    }

    private function getCouchClient(string $dbName): CouchClient
    {
        return new CouchClient($this->dsn, $dbName);
    }

    public function test(): JsonResponse
    {
        return new JsonResponse($this->dsn, 200);
    }

    private function doLoginForUser(Voter $voter): string
    {
        $voter->generateToken();
        $client = $this->getCouchClient('voters');
        $voterDoc = $client->getDoc($voter->getId());
        $updatedVoterDoc = $voter->toCouchDocument();
        $updatedVoterDoc->_rev = $voterDoc->_rev;

        $client->storeDoc($updatedVoterDoc);
        return $voter->getToken();
    }

    private function doLoginForAdmin(Challenge $challenge): string
    {
        $challenge->generateAdminToken();
        $client = $this->getCouchClient($challenge->getName());
        $client->storeDoc($challenge->toCouchDocument());
        return $challenge->getAdminToken();
    }

    public function isVoterTokenValid(string $tokenShown, $voterId): bool
    {
        $voter = $this->getVoter($voterId);

        return ($voter->getToken() !== null && $voter->getToken() === $tokenShown);
    }

    public function isAdminTokenValid(string $tokenShown, $challengeName): bool
    {
        $challengeInfo = $this->getCouchClient($challengeName)->getDoc('info');
        $challenge = Challenge::fromCouchDocument(json_decode(json_encode($challengeInfo), true));

        return ($challenge->getAdminToken() !== null && $challenge->getAdminToken() === $tokenShown);
    }
    public function changePassForVoter(string $voterName, string $newPass): bool
    {
        $logger = new Logger("users");
        $logger->pushHandler(new StreamHandler(self::LOGIN_LOG_PATH, Logger::NOTICE));
        try {
            $logger->notice($voterName . " is resetting password");
            $voterClient = $this->getCouchClient('voters');
            $voterDoc = $voterClient->find([
                'name' => $voterName
            ])[0];

            $voter = Voter::fromCouchDocument($voterDoc);
            $hashed_password = password_hash($newPass, PASSWORD_BCRYPT);
            if ($hashed_password === false || $hashed_password === null) {
                throw new \Exception('Failed Hashing Password, creation of Voter aborted');
            }
            $voter->setAuthKey($hashed_password);
            $updatedVoterDoc = $voter->toCouchDocument();
            $updatedVoterDoc->_rev = $voterDoc->_rev;

            $voterClient->storeDoc($updatedVoterDoc);
            $logger->notice("success");
            return true;
        } catch (\Exception $exception) {
            $logger->alert($exception->getMessage());
//            dump($exception); die;
            return false;
        }
    }

    private function addVoterToChallenge(string $challengeName, Voter $voter)
    {
        $voterClient = $this->getCouchClient('voters');
        $challengeClient = $this->getCouchClient($challengeName);

        $voterClient->storeDoc($voter->toCouchDocument());

        $data = json_decode(json_encode($challengeClient->getDoc('info')), true);
        $challenge = Challenge::fromCouchDocument($data);

        $challenge->addVoterToChallenge($voter);
        $challenge->setRevisionNumber($data['_rev']);
        $challengeClient->storeDoc($challenge->toCouchDocument());
    }
}
