<?php
declare(strict_types=1);

namespace App\Services;

use Exception;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class GoogleDriveService
{
    private Google_Client $googleClient;
    private Google_Service_Drive $googleServiceDrive;
    private Logger $googleDriveLogger;

    public function __construct()
    {
        $this->googleClient = $this->getClient();
        $this->googleServiceDrive = new Google_Service_Drive($this->googleClient);
        $this->googleDriveLogger = new Logger('general');
        $this->googleDriveLogger->pushHandler(new StreamHandler('logs/general.log', Logger::NOTICE));
    }

    public function listFilesInFolder(string $folderId)
    {
        $optParams = array(
            'pageSize' => 100,
            'fields' => "nextPageToken, files(contentHints/thumbnail,fileExtension,iconLink,id,name,size,thumbnailLink,webContentLink,webViewLink,mimeType,parents)",
            'q' => "'".$folderId."' in parents"
        );
        $results = $this->googleServiceDrive->files->listFiles($optParams);

        return ($results->getFiles());
    }

    public function uploadFileToGoogleDrive($driveFile, $folderId): string
    {
        $fileMetadata = new Google_Service_Drive_DriveFile(['name' => $driveFile['name']]);
        $fileMetadata->setParents([$folderId]);
        $content = file_get_contents($driveFile['tmp_name']);
        $mimeType = mime_content_type($driveFile['tmp_name']);

        try {
            $file = $this->googleServiceDrive->files->create(
                $fileMetadata, [
                    'data' => $content,
                    'mimeType' => $mimeType,
                    'fields' => 'id'
                ]
            );
        } catch (Exception $exception) {
            $this->googleDriveLogger->error("Error from google drive: " . $exception->getMessage());
            return 'failed';
        }
        return $file->id;
    }

    private function getClient(): Google_Client
    {
        $client = new Google_Client();
        $client->setApplicationName('Google Drive API PHP Quickstart');
        $client->setScopes(Google_Service_Drive::DRIVE);
        $client->setAuthConfig(__DIR__ . '/../../credentials.json');
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');

        $tokenPath = __DIR__ . '/../../token.json';
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
        }

        // If there is no previous token or it's expired.
        if ($client->isAccessTokenExpired()) {
            // Refresh the token if possible, else fetch a new one.
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                // Request authorization from the user.
                $authUrl = $client->createAuthUrl();
                printf("Open the following link in your browser:\n%s\n", $authUrl);
                print 'Enter verification code: ';
                $authCode = trim(fgets(STDIN));

                // Exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);

                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new Exception(join(', ', $accessToken));
                }
            }
            // Save the token to a file.
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
        return $client;
    }
}
