<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DriveOAuthController;
use App\Entity\Doctrine\DbChallenge;
use App\Entity\Doctrine\DbStorageResource;
use App\Entity\StorageType;
use App\Repository\StorageResourceRepositoryInterface;
use App\Services\GoogleDriveService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class DriveOAuthControllerTest extends TestCase
{
    // --- helpers ---

    private function makeController(
        EntityManagerInterface $em,
        GoogleDriveService $drive,
        StorageResourceRepositoryInterface $storage,
        bool $isAdmin = true,
        bool $csrfValid = true,
    ): DriveOAuthController {
        return new class ($em, $drive, $storage, $isAdmin, $csrfValid) extends DriveOAuthController {
            public array $flashes = [];

            public function __construct(
                EntityManagerInterface $em,
                GoogleDriveService $drive,
                StorageResourceRepositoryInterface $storage,
                private readonly bool $isAdmin,
                private readonly bool $csrfValid,
            ) {
                parent::__construct($em, $drive, $storage);
            }

            protected function isAdminForChallenge(Request $request, string $challengeName): bool
            {
                return $this->isAdmin;
            }

            public function generateUrl(string $route, array $parameters = [], int $referenceType = 0): string
            {
                return '/generated/' . $route;
            }

            public function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
            {
                return new RedirectResponse('/redirect/' . $route, $status);
            }

            public function addFlash(string $type, mixed $message): void
            {
                $this->flashes[$type][] = $message;
            }

            protected function isCsrfTokenValid(string $id, mixed $token): bool
            {
                return $this->csrfValid;
            }
        };
    }

    private function makeRequestWithSession(array $query = [], array $sessionData = []): Request
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn(string $key) => $sessionData[$key] ?? null);
        $session->method('remove')->willReturnSelf();
        $session->method('set')->willReturnSelf();

        $request = new Request($query);
        $request->setSession($session);
        return $request;
    }

    private function makeEmWithChallenge(?DbChallenge $dbChallenge): EntityManagerInterface
    {
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findOneBy')->willReturn($dbChallenge);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        return $em;
    }

    private function encodeState(string $challengeName, string $nonce): string
    {
        return base64_encode(json_encode(['challenge' => $challengeName, 'nonce' => $nonce]));
    }

    // --- connect ---

    public function test_connect_redirects_non_admin_to_login(): void
    {
        $request = $this->makeRequestWithSession();
        $ctrl    = $this->makeController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(GoogleDriveService::class),
            $this->createMock(StorageResourceRepositoryInterface::class),
            isAdmin: false,
        );

        $response = $ctrl->connect($request, 'rally');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('loginMenu', $response->getTargetUrl());
    }

    // --- callback ---

    public function test_callback_returns_400_on_nonce_mismatch(): void
    {
        $nonce   = 'correct-nonce';
        $state   = $this->encodeState('rally', 'wrong-nonce');
        $request = $this->makeRequestWithSession(
            query: ['state' => $state],
            sessionData: ['drive_oauth_nonce_rally' => $nonce],
        );

        $ctrl     = $this->makeController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(GoogleDriveService::class),
            $this->createMock(StorageResourceRepositoryInterface::class),
        );
        $response = $ctrl->callback($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function test_callback_stores_credentials_with_folder_id(): void
    {
        $nonce       = 'nonce-abc';
        $code        = 'auth-code-123';
        $state       = $this->encodeState('rally', $nonce);
        $request     = $this->makeRequestWithSession(
            query: ['state' => $state, 'code' => $code],
            sessionData: ['drive_oauth_nonce_rally' => $nonce],
        );

        $tokens = ['access_token' => 'tok', 'refresh_token' => 'rtok'];

        $drive = $this->createMock(GoogleDriveService::class);
        $drive->method('exchangeCodeForTokens')->willReturn($tokens);
        $drive->method('getConnectedEmail')->willReturn('user@example.com');
        $drive->method('createFolder')->willReturn('folder-xyz');

        $dbChallenge = $this->createMock(DbChallenge::class);
        $dbChallenge->method('getId')->willReturn(42);

        $savedResource = null;
        $storage = $this->createMock(StorageResourceRepositoryInterface::class);
        $storage->method('findForChallenge')->willReturn(null);
        $storage->expects($this->once())->method('save')
            ->willReturnCallback(static function (DbStorageResource $r) use (&$savedResource): void {
                $savedResource = $r;
            });

        $ctrl     = $this->makeController($this->makeEmWithChallenge($dbChallenge), $drive, $storage);
        $response = $ctrl->callback($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($savedResource);
        $this->assertSame('folder-xyz', $savedResource->getCredentials()['drive_folder_id']);
        $this->assertSame('user@example.com', $savedResource->getCredentials()['cached_email']);
    }

    public function test_callback_upserts_existing_resource_on_reconnect(): void
    {
        $nonce   = 'nonce-xyz';
        $state   = $this->encodeState('rally', $nonce);
        $request = $this->makeRequestWithSession(
            query: ['state' => $state, 'code' => 'code'],
            sessionData: ['drive_oauth_nonce_rally' => $nonce],
        );

        $dbChallenge = $this->createMock(DbChallenge::class);
        $dbChallenge->method('getId')->willReturn(7);

        $existing = new DbStorageResource($dbChallenge, StorageType::GoogleDrive);
        $existing->setCredentials(['old' => 'creds', 'drive_folder_id' => 'old-folder']);

        $drive = $this->createMock(GoogleDriveService::class);
        $drive->method('exchangeCodeForTokens')->willReturn(['access_token' => 'new']);
        $drive->method('getConnectedEmail')->willReturn(null);
        $drive->method('createFolder')->willReturn('new-folder');

        $storage = $this->createMock(StorageResourceRepositoryInterface::class);
        $storage->method('findForChallenge')->willReturn($existing);
        $storage->expects($this->once())->method('save')->with($existing);
        $storage->expects($this->never())->method('delete');

        $ctrl = $this->makeController($this->makeEmWithChallenge($dbChallenge), $drive, $storage);
        $ctrl->callback($request);

        $this->assertSame('new-folder', $existing->getCredentials()['drive_folder_id']);
    }

    // --- disconnect ---

    public function test_disconnect_returns_403_on_csrf_failure(): void
    {
        $request = new Request([], ['_token' => 'bad-token']);

        $ctrl     = $this->makeController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(GoogleDriveService::class),
            $this->createMock(StorageResourceRepositoryInterface::class),
            csrfValid: false,
        );
        $response = $ctrl->disconnect($request, 'rally');

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_disconnect_deletes_resource(): void
    {
        $request = new Request([], ['_token' => 'good-token']);

        $dbChallenge = $this->createMock(DbChallenge::class);
        $dbChallenge->method('getId')->willReturn(5);

        $resource = new DbStorageResource($dbChallenge, StorageType::GoogleDrive);

        $storage = $this->createMock(StorageResourceRepositoryInterface::class);
        $storage->method('findForChallenge')->willReturn($resource);
        $storage->expects($this->once())->method('delete')->with($resource);

        $ctrl = $this->makeController($this->makeEmWithChallenge($dbChallenge), $this->createMock(GoogleDriveService::class), $storage);
        $response = $ctrl->disconnect($request, 'rally');

        $this->assertSame(302, $response->getStatusCode());
    }
}
