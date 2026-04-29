<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\GoogleDriveService;
use Google\Service\Drive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UploadController extends AbstractController
{
    public function listFilesFromGoogleDrive(GoogleDriveService $googleDriveService, $folderId): JsonResponse
    {
        $returnArray = [];
        foreach ($googleDriveService->listFilesInFolder($folderId) as $file) {
            /** @var Drive\DriveFile $file */
            $returnArray[$file->getName()] = $file->getId();
        }
        return new JsonResponse($returnArray);
    }

    public function uploadFileToMyDriveForm(): Response
    {
        return $this->render('/default/uploadFileForm.html.twig');
    }

    public function uploadFileToDrive(GoogleDriveService $googleDriveService, Request $request): Response
    {
        if (!empty($_FILES["fileToUpload"]["name"])) {
            $fileToUpload = $_FILES["fileToUpload"];
            $googleDriveFolderId = $_POST['folderId'];
            $fileId = $googleDriveService->uploadFileToGoogleDrive($fileToUpload, $googleDriveFolderId);
            return $this->render('/default/uploadFileForm.html.twig', [
                'message' => $fileId,
                'folderId' => $googleDriveFolderId
            ]);
        }
        return $this->render('/default/uploadFileForm.html.twig');
    }
}
