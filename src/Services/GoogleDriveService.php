<?php
declare(strict_types=1);

namespace App\Services;

use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;

class GoogleDriveService
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function createAuthUrl(string $state, string $redirectUri): string
    {
        $client = $this->buildBaseClient();
        $client->setState($state);
        $client->setRedirectUri($redirectUri);
        return $client->createAuthUrl();
    }

    public function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $client = $this->buildBaseClient();
        $client->setRedirectUri($redirectUri);
        $tokens = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($tokens['error'])) {
            throw new \RuntimeException('Failed to exchange authorization code: ' . ($tokens['error_description'] ?? $tokens['error']));
        }
        return $tokens;
    }

    public function getConnectedEmail(array $credentials): ?string
    {
        $client = $this->buildBaseClient();
        $client->setAccessToken($credentials);
        try {
            $http     = $client->authorize();
            $response = $http->get('https://www.googleapis.com/oauth2/v2/userinfo');
            $data     = json_decode((string) $response->getBody(), true);
            return $data['email'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function createFolder(string $name, array $credentials, callable $onCredentialsRefreshed): string
    {
        $client = $this->buildClientFromCredentials($credentials, $onCredentialsRefreshed);
        $drive  = new Google_Service_Drive($client);
        $folder = new Google_Service_Drive_DriveFile([
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        $created = $drive->files->create($folder, ['fields' => 'id']);
        return $created->getId();
    }

    public function uploadFile(array $file, string $folderId, array $credentials, callable $onCredentialsRefreshed): string
    {
        $client   = $this->buildClientFromCredentials($credentials, $onCredentialsRefreshed);
        $drive    = new Google_Service_Drive($client);
        $metadata = new Google_Service_Drive_DriveFile([
            'name'    => $file['name'],
            'parents' => [$folderId],
        ]);
        $mimeType = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
        $created  = $drive->files->create($metadata, [
            'data'     => file_get_contents($file['tmp_name']),
            'mimeType' => $mimeType,
            'fields'   => 'id',
        ]);
        return $created->getId();
    }

    private function buildClientFromCredentials(array $credentials, callable $onCredentialsRefreshed): Google_Client
    {
        $client = $this->buildBaseClient();
        $client->setAccessToken($credentials);
        if ($client->isAccessTokenExpired()) {
            if (!$client->getRefreshToken()) {
                throw new \RuntimeException('Storage credentials expired and no refresh token available');
            }
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            $onCredentialsRefreshed($client->getAccessToken());
        }
        return $client;
    }

    private function buildBaseClient(): Google_Client
    {
        $client = new Google_Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setScopes([
            Google_Service_Drive::DRIVE_FILE,
            'https://www.googleapis.com/auth/userinfo.email',
        ]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        return $client;
    }
}
