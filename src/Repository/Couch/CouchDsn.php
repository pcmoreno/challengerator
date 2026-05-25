<?php
declare(strict_types=1);

namespace App\Repository\Couch;

final class CouchDsn
{
    private function __construct(
        public readonly string $baseUrl,
        public readonly ?string $user,
        public readonly ?string $pass,
    ) {}

    public static function fromString(string $dsn): self
    {
        $parsed = parse_url($dsn);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException(sprintf('Invalid CouchDB DSN: "%s"', $dsn));
        }
        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'CouchDB DSN scheme must be http or https, got "%s"',
                $parsed['scheme'],
            ));
        }
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return new self(
            baseUrl: $parsed['scheme'] . '://' . $parsed['host'] . $port,
            user: isset($parsed['user']) ? rawurldecode($parsed['user']) : null,
            pass: isset($parsed['pass']) ? rawurldecode($parsed['pass']) : null,
        );
    }
}
