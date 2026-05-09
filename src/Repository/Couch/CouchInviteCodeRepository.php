<?php
declare(strict_types=1);

namespace App\Repository\Couch;

use App\Repository\InviteCodeRepositoryInterface;
use PHPOnCouch\CouchClient;

class CouchInviteCodeRepository implements InviteCodeRepositoryInterface
{
    private CouchClient $client;

    public function __construct(string $dsn)
    {
        $this->client = new CouchClient($dsn, 'codes');
    }

    public function validateAndConsume(string $code): bool
    {
        $codesDoc = $this->client->getDoc('codigos');
        if (!in_array($code, $codesDoc->createDbCodes)) {
            return false;
        }
        $codesDoc->createDbCodes = array_values(array_diff($codesDoc->createDbCodes, [$code]));
        $this->client->storeDoc($codesDoc);
        return true;
    }
}
