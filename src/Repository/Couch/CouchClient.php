<?php
declare(strict_types=1);

namespace App\Repository\Couch;

use App\Exception\CouchDBException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CouchClient
{
    public function __construct(
        private readonly CouchDsn $dsn,
        private readonly HttpClientInterface $http,
    ) {}

    public function databaseExists(string $db): bool
    {
        return $this->request('HEAD', rawurlencode($db))->getStatusCode() === 200;
    }

    public function createDatabase(string $db): void
    {
        $status = $this->request('PUT', rawurlencode($db))->getStatusCode();
        // 412 = already exists; treat as idempotent success (closes C6 TOCTOU race).
        if ($status === 201 || $status === 202 || $status === 412) {
            return;
        }
        throw new CouchDBException(sprintf('createDatabase failed for "%s" (HTTP %d)', $db, $status));
    }

    public function deleteDatabase(string $db): void
    {
        $status = $this->request('DELETE', rawurlencode($db))->getStatusCode();
        if ($status === 200 || $status === 202 || $status === 404) {
            return;
        }
        throw new CouchDBException(sprintf('deleteDatabase failed for "%s" (HTTP %d)', $db, $status));
    }

    /** @param array<string,mixed> $doc */
    public function storeDoc(string $db, array $doc): void
    {
        if (!isset($doc['_id'])) {
            throw new \InvalidArgumentException('storeDoc requires _id in document');
        }
        $id = (string) $doc['_id'];
        $path = rawurlencode($db) . '/' . rawurlencode($id);
        $status = $this->request('PUT', $path, ['json' => $doc])->getStatusCode();
        // 409 = revision conflict from duplicate delivery; the doc is already there,
        // so treat as success (closes C10 duplicate-delivery handling).
        if ($status === 201 || $status === 202 || $status === 409) {
            return;
        }
        throw new CouchDBException(sprintf('storeDoc failed for "%s/%s" (HTTP %d)', $db, $id, $status));
    }

    /** @return array<string,mixed>|null */
    public function getDoc(string $db, string $id): ?array
    {
        $response = $this->request('GET', rawurlencode($db) . '/' . rawurlencode($id));
        $status = $response->getStatusCode();
        if ($status === 404) {
            return null;
        }
        if ($status !== 200) {
            throw new CouchDBException(sprintf('getDoc failed for "%s/%s" (HTTP %d)', $db, $id, $status));
        }
        return $response->toArray();
    }

    /**
     * @param array<string,mixed> $selector
     * @param list<array<string,string>> $sort
     * @return list<array<string,mixed>>
     */
    public function find(string $db, array $selector, array $sort, int $limit, int $skip): array
    {
        $body = [
            'selector' => $selector,
            'sort' => $sort,
            'limit' => $limit,
            'skip' => $skip,
        ];
        $response = $this->request('POST', rawurlencode($db) . '/_find', ['json' => $body]);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new CouchDBException(sprintf('find failed for "%s" (HTTP %d)', $db, $status));
        }
        $payload = $response->toArray();
        return $payload['docs'] ?? [];
    }

    /** @param list<string> $fields */
    public function createIndex(string $db, array $fields, string $name): void
    {
        $body = [
            'index' => ['fields' => $fields],
            'name' => $name,
            'type' => 'json',
        ];
        $status = $this->request('POST', rawurlencode($db) . '/_index', ['json' => $body])->getStatusCode();
        if ($status === 200 || $status === 201) {
            return;
        }
        throw new CouchDBException(sprintf('createIndex failed for "%s/%s" (HTTP %d)', $db, $name, $status));
    }

    /** @param array<string,mixed> $options */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        if ($this->dsn->user !== null) {
            $options['auth_basic'] = [$this->dsn->user, $this->dsn->pass ?? ''];
        }
        return $this->http->request($method, $this->dsn->baseUrl . '/' . $path, $options);
    }
}
