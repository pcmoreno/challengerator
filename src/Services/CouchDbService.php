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
    /** @var string  */
    private $couchDB;
    /** @var CouchClient  */
    private $client;

    public function __construct(
        string $couchDsn,
        string $couchDB
    ) {
        $this->client = new CouchClient($couchDsn, $couchDB);
        $this->couchDB = $couchDB;
        $this->couchDsn = $couchDsn;
    }

    public function createDB(?string $dbName): JsonResponse
    {
        if ($dbName !== null) {
            $this->client = new CouchClient($this->couchDsn, $dbName);
        }
        try {
            $result = $this->client->createDatabase();
        } catch (CouchException $e) {
            return new JsonResponse(
                "We issued the request, but couch server returned an error.\n" .
                "We can have HTTP Status code returned by couchDB using \$e->getCode() : " . $e->getCode() . "\n" .
                "We can have error message returned by couchDB using \$e->getMessage() : " . $e->getMessage() . "\n" .
                "Finally, we can have CouchDB's complete response body using \$e->getBody() : " . $e->getBody(). "\n" .
                "Are you sure that your CouchDB server is at $this->couchDsn, and that database does not exist ?\n",
                500
            );
        } catch (Exception $e) {
            return new JsonResponse(
                "It seems that something wrong happened. You can have more details using :\n" .
                "the exception class with get_class(\$e) : " . get_class($e) . "\n" .
                "the exception error code with \$e->getCode() : " . $e->getCode() . "\n" .
                "the exception error message with \$e->getMessage() : " . $e->getMessage(),
                500
            );
        }
        return new JsonResponse(
            "Database successfully created. CouchDB sent the response :" . $result ."\n",
            JsonResponse::HTTP_CREATED
        );
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

    public function fetchDocument(string $id): JsonResponse
    {
        try {
            return new JsonResponse($this->client->getDoc($id));
        } catch (Exception $exception) {
            return new JsonResponse($exception->getMessage(), 400);
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
        foreach ($docBody as $key=>$value) {
            $doc->$key = $value;
        }
        try {
            return new JsonResponse($this->client->storeDoc($doc));
        } catch (Exception $exception) {
            return new JsonResponse($exception->getMessage(), 400);
        }
    }

    public function updateDocument(?string $id, array $docBody): JsonResponse
    {
        // TODO
        return new JsonResponse('not done yet', 666);
    }
}