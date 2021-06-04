<?php
declare(strict_types=1);

namespace App\Services;

use Exception;
use PHPOnCouch\CouchClient;
use PHPOnCouch\Exceptions\CouchException;
use Ramsey\Uuid\Nonstandard\Uuid;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;

class CouchDbService
{
    /** @var string  */
    private $couchDsn;
    /** @var CouchClient  */
    private $client;

    public function __construct(
        string $couchDsn,
        string $couchDB
    ) {
        $this->client = new CouchClient($couchDsn, $couchDB);
        $this->couchDsn = $couchDsn;
    }

    public function createDB(?string $dbName, $code): JsonResponse
    {
        $codeService = new CodeService($this->couchDsn);
        $success = $codeService->validateAndConsumeCode($code);
        if (!$success) {
            return new JsonResponse('Code not valid', JsonResponse::HTTP_FORBIDDEN);
        }

        if ($dbName !== null) {
            $this->client = new CouchClient($this->couchDsn, $dbName);
        }
        try {
            $result = $this->client->createDatabase();
        } catch (CouchException $e) {
            return new JsonResponse(
                'CouchException: ' . $e->getMessage(),
                500
            );
        } catch (Exception $e) {
            return new JsonResponse(
                $e->getMessage(),
                500
            );
        }
        return new JsonResponse(
            "Database successfully created.",
            JsonResponse::HTTP_CREATED
        );
    }

    public function deleteDb(string $dbName, $pass): JsonResponse
    {
        if ($this->listDatabasesInfo($dbName)->getStatusCode() !== 200)
        {
            return new JsonResponse('db not found', 404);
        }
        try {
            $this->client = new CouchClient($this->couchDsn, $dbName);
            $info = $this->client->getDoc('info');
            if ($info->_owner === $pass) {
                $this->client->deleteDatabase();
            } else {
                return new JsonResponse('Nope', 403);
            }
        } catch (Exception $exception) {
            return new JsonResponse('Error', 500);
        }

        return new JsonResponse('Database ' . $dbName . ' deleted.');
    }

    public function listDatabasesInfo(?string $dbName): JsonResponse
    {
        if ($dbName !== null) {
            $this->client = new CouchClient($this->couchDsn, $dbName);
        }
        try {
            return new JsonResponse($this->client->getDatabaseInfos());
        } catch (Exception $exception) {
            return new JsonResponse($exception->getMessage(), 500);
        }
    }

    public function getDatabaseList(): array
    {
        try {
            return (array) $this->client->listDatabases();
        } catch (Exception $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    public function fetchDocument(string $id, ?string $dbName): JsonResponse
    {
        if ($dbName !== null) {
            $this->client = new CouchClient($this->couchDsn, $dbName);
        }
        try {
            return new JsonResponse($this->client->getDoc($id));
        } catch (Exception $exception) {
            return new JsonResponse($exception->getMessage(), 400);
        }
    }

    // probs not getting used
    public function fetchDocumentData(string $id, ?string $dbName)
    {
        if ($dbName !== null) {
            $this->client = new CouchClient($this->couchDsn, $dbName);
        }
        try {
            return (array)($this->client->getDoc($id));
        } catch (Exception $exception) {
            return ['error'];
        }
    }

    public function createDocument(?string $id, array $docBody, ?CouchClient $couchClient): JsonResponse
    {
        if ($couchClient !== null) {
            $this->client = $couchClient;
        }
        $doc = new stdClass();
        $id = $id === null ? Uuid::uuid4()->toString() : $id;
        $doc->_id = $id;
        foreach ($docBody as $key => $value) {
            $doc->$key = $value;
        }
        try {
            return new JsonResponse($this->client->storeDoc($doc));
        } catch (Exception $exception) {
            return new JsonResponse($exception->getMessage(), 400);
        }
    }

    public function updateDocument(string $id, string $dbName, array $docBody): JsonResponse
    {
        try {
            $this->client = new CouchClient($this->couchDsn, $dbName);

            $doc = $this->client->getDoc($id);
            foreach ($docBody as $key => $value) {
                $doc->$key = $value;
            }
            return new JsonResponse($this->client->storeDoc($doc));
        } catch (Exception $exception) {
            return new JsonResponse($exception->getMessage(), 400);
        }
    }
}