<?php
declare(strict_types=1);

namespace App\Services;

use PHPOnCouch\CouchClient;
use Symfony\Component\HttpFoundation\JsonResponse;

class CodeService
{
    /** @var CouchClient  */
    private $client;

    public function __construct(string $couchDsn)
    {
        $this->client = new CouchClient($couchDsn, 'codes');
    }

    public function validateAndConsumeCode($code): JsonResponse
    {

    }
}
