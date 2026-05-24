<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Doctrine\DbChallenge;
use App\Entity\Doctrine\DbStorageResource;
use App\Entity\StorageType;
use App\Repository\StorageResourceRepositoryInterface;
use App\Services\GoogleDriveService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DriveOAuthController extends AbstractController
{
    use AdminGuardTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private GoogleDriveService $driveService,
        private StorageResourceRepositoryInterface $storageRepository,
    ) {}

    public function connect(Request $request, string $challengeName): Response
    {
        if (!$this->isAdminForChallenge($request, $challengeName)) {
            return $this->redirectToRoute('loginMenu');
        }

        $nonce = bin2hex(random_bytes(16));
        $request->getSession()->set('drive_oauth_nonce_' . $challengeName, $nonce);

        $state       = base64_encode(json_encode(['challenge' => $challengeName, 'nonce' => $nonce]));
        $redirectUri = $this->generateUrl('drive_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->redirect($this->driveService->createAuthUrl($state, $redirectUri));
    }

    public function callback(Request $request): Response
    {
        $rawState  = $request->query->getString('state');
        $stateData = json_decode(base64_decode($rawState), true);

        if (!is_array($stateData) || !isset($stateData['challenge'], $stateData['nonce'])) {
            return new Response('Invalid state parameter', Response::HTTP_BAD_REQUEST);
        }

        $challengeName = $stateData['challenge'];
        $nonce         = $stateData['nonce'];

        if (!$this->isAdminForChallenge($request, $challengeName)) {
            return $this->redirectToRoute('loginMenu');
        }

        $sessionKey  = 'drive_oauth_nonce_' . $challengeName;
        $storedNonce = $request->getSession()->get($sessionKey);
        $request->getSession()->remove($sessionKey);

        if (!$storedNonce || !hash_equals($storedNonce, $nonce)) {
            return new Response('Invalid nonce', Response::HTTP_BAD_REQUEST);
        }

        if ($request->query->has('error')) {
            $this->addFlash('warning', 'Google Drive access was denied.');
            return $this->redirectToRoute('addCarToChallengeFormPage', ['challengeName' => $challengeName]);
        }

        $redirectUri = $this->generateUrl('drive_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $credentials = $this->driveService->exchangeCodeForTokens($request->query->getString('code'), $redirectUri);
        $credentials['cached_email'] = $this->driveService->getConnectedEmail($credentials);

        $folderId = $this->driveService->createFolder(
            'Challengerator - ' . $challengeName,
            $credentials,
            static function (array $newCredentials) use (&$credentials): void { $credentials = $newCredentials; }
        );
        $credentials['drive_folder_id'] = $folderId;

        $dbChallenge = $this->em->getRepository(DbChallenge::class)->findOneBy(['name' => $challengeName]);
        if ($dbChallenge === null) {
            return new Response("Challenge not found: $challengeName", Response::HTTP_NOT_FOUND);
        }

        $resource = $this->storageRepository->findForChallenge($dbChallenge->getId(), StorageType::GoogleDrive)
            ?? new DbStorageResource($dbChallenge, StorageType::GoogleDrive);
        $resource->setCredentials($credentials);
        $this->storageRepository->save($resource);

        $this->addFlash('success', 'Google Drive connected.');
        return $this->redirectToRoute('addCarToChallengeFormPage', ['challengeName' => $challengeName]);
    }

    public function disconnect(Request $request, string $challengeName): Response
    {
        if (!$this->isAdminForChallenge($request, $challengeName)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('drive_disconnect_' . $challengeName, $request->request->getString('_token'))) {
            return new Response('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        $dbChallenge = $this->em->getRepository(DbChallenge::class)->findOneBy(['name' => $challengeName]);
        if ($dbChallenge !== null) {
            $resource = $this->storageRepository->findForChallenge($dbChallenge->getId(), StorageType::GoogleDrive);
            if ($resource !== null) {
                $this->storageRepository->delete($resource);
            }
        }

        return $this->redirectToRoute('addCarToChallengeFormPage', ['challengeName' => $challengeName]);
    }
}
