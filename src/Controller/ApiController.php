<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Challenge\Outcome;
use App\Services\CouchDbService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ApiController extends AbstractController
{
    private CouchDbService $couchService;

    public function __construct(CouchDbService $couchService)
    {
        $this->couchService = $couchService;
    }

    public function apitest(): JsonResponse
    {
        $data['message'] = "it's working fine, now go do something better";
        $data['status'] = JsonResponse::HTTP_OK;
        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }

    public function createDB(?string $dbName): JsonResponse
    {
        return $this->couchService->createDB($dbName);
    }

    public function databaseInfos(?string $dbName): JsonResponse
    {
        return $this->couchService->listDatabasesInfo($dbName);
    }

    public function fetchDocumentWithId(string $id): JsonResponse
    {
        return $this->couchService->fetchDocument($id);
    }

    public function createDocument(Request $request): JsonResponse
    {
        return new JsonResponse(($request->request->all()));
        return $this->couchService->createDocument($id, $documentBody, null);
    }

    public function updateDocument(?string $id, array $documentBody): JsonResponse
    {
        return $this->couchService->updateDocument($id, $documentBody, null);
    }
}