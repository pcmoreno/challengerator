<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\ChallengeService;
use App\Services\CouchDbService;
use PHPOnCouch\CouchClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ApiController extends AbstractController
{
    private CouchDbService $couchService;
    private string $dsn;
    private CouchClient $carsDb;
    private CouchClient $votersDb;

    public function __construct(CouchDbService $couchService, string $dsn)
    {
        $this->couchService = $couchService;
        $this->dsn = $dsn;
        $this->carsDb = $this->getCouchClient('cars');
        $this->votersDb = $this->getCouchClient('voters');
    }

    public function apitest(): JsonResponse
    {
        $data['message'] = "it's working fine, now go do something better";
        $data['status'] = JsonResponse::HTTP_OK;
        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }

    public function createDb(?string $dbName, string $code): JsonResponse
    {
        return $this->couchService->createDB($dbName, $code);
    }

    public function deleteDb(string $dbName, string $adminPass): JsonResponse
    {
        return $this->couchService->deleteDb($dbName, $adminPass);
    }

    public function databaseInfos(?string $dbName): JsonResponse
    {
        return $this->couchService->listDatabasesInfo($dbName);
    }

    public function fetchDocumentWithId(string $dbName, string $id): JsonResponse
    {
        return $this->couchService->fetchDocument($id, $dbName);
    }

    public function createDocument(Request $request): JsonResponse // TODO
    {
        return new JsonResponse(($request->request->all()));
        return $this->couchService->createDocument($id, $documentBody, null);
    }

//    public function updateDocumentWithId(string $dbName, string $id, array $documentBody): JsonResponse
//    {
//        return $this->couchService->updateDocument($id, $dbName, $documentBody);
//    }

    public function addCarAndVoterToChallenge(Request $request, string $challengeName): JsonResponse
    {
        $challengeService = new ChallengeService($this->getCouchClient($challengeName));
        $challenges = $this->couchService->getDatabaseList();
        if (!in_array($challengeName, $challenges)) {
            return new JsonResponse('Database does not exist', 404);
        }

        $challengeCars = $this->couchService->fetchDocumentData("cars", $challengeName);
        $challengeVoters = $this->couchService->fetchDocumentData("participants", $challengeName);

        $cars = $challengeService->addDataToCars($challengeCars, $request);
        $voters = $challengeService->addDataToVoters($challengeCars, $request);

        try {
            // TODO: NOT WORKING AT ALL YET
            $this->couchService->updateDocument('cars', $challengeName, $cars);
            $this->couchService->updateDocument('participants', $challengeName, $voters);
        } catch (\Exception $exception) {
            return new JsonResponse($exception->getMessage(), 500);
        }

        dump($challengeCars);
        dump($challengeVoters); die;

        return $this->couchService->listDatabases();
    }

    private function getCouchClient($dbName): CouchClient
    {
        return new CouchClient($this->dsn, $dbName);
    }
}