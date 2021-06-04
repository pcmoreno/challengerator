<?php
declare(strict_types=1);

namespace App\Services;

use PHPOnCouch\CouchClient;

class CodeService
{
    /** @var CouchClient  */
    private $client;

    public function __construct(string $couchDsn)
    {
        $this->client = new CouchClient($couchDsn, 'codes');
    }

    public function validateAndConsumeCode($code): bool
    {
        $codesDoc = $this->client->getDoc('codigos');

        if (in_array($code, $codesDoc->createDbCodes)) {
            $codes = array_diff($codesDoc->createDbCodes, [$code]);
            $codesDoc->createDbCodes = array_values($codes);
            $this->client->storeDoc($codesDoc);
            return true;
        }

        return false;
    }
}
