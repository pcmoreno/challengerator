<?php
declare(strict_types=1);

namespace App\Tests\Services;

use App\Services\GoogleDriveService;
use Google_Client;
use PHPUnit\Framework\TestCase;

class GoogleDriveServiceTest extends TestCase
{
    public function test_createAuthUrl_contains_google_accounts_domain(): void
    {
        $service = new GoogleDriveService('fake-client-id', 'fake-secret');
        $url = $service->createAuthUrl('my-state', 'https://example.com/callback');

        $this->assertIsString($url);
        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('my-state', urldecode($url));
    }

    public function test_exchangeCodeForTokens_throws_on_error_response(): void
    {
        $mockClient = $this->createMock(Google_Client::class);
        $mockClient->method('fetchAccessTokenWithAuthCode')
            ->willReturn(['error' => 'access_denied', 'error_description' => 'User denied access']);

        $service = new class ('id', 'secret', $mockClient) extends GoogleDriveService {
            public function __construct(
                string $clientId,
                string $clientSecret,
                private readonly Google_Client $mockClient,
            ) {
                parent::__construct($clientId, $clientSecret);
            }

            protected function buildBaseClient(): Google_Client
            {
                return $this->mockClient;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/User denied access/');
        $service->exchangeCodeForTokens('auth-code', 'https://example.com/callback');
    }

    public function test_buildClientFromCredentials_invokes_callback_on_token_refresh(): void
    {
        $newTokens = ['access_token' => 'new-token', 'refresh_token' => 'rtoken'];

        $mockClient = $this->createMock(Google_Client::class);
        $mockClient->method('isAccessTokenExpired')->willReturn(true);
        $mockClient->method('getRefreshToken')->willReturn('old-refresh');
        $mockClient->expects($this->once())->method('fetchAccessTokenWithRefreshToken');
        $mockClient->method('getAccessToken')->willReturn($newTokens);

        $service = $this->makeTestableService($mockClient);

        $callbackCredentials = null;
        $service->testBuildClientFromCredentials(
            ['access_token' => 'expired'],
            static function (array $creds) use (&$callbackCredentials): void {
                $callbackCredentials = $creds;
            }
        );

        $this->assertSame($newTokens, $callbackCredentials);
    }

    public function test_buildClientFromCredentials_throws_when_no_refresh_token(): void
    {
        $mockClient = $this->createMock(Google_Client::class);
        $mockClient->method('isAccessTokenExpired')->willReturn(true);
        $mockClient->method('getRefreshToken')->willReturn(null);

        $service = $this->makeTestableService($mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/expired/i');
        $service->testBuildClientFromCredentials(['access_token' => 'expired'], static fn() => null);
    }

    /** @return GoogleDriveService&object{testBuildClientFromCredentials(array, callable): Google_Client} */
    private function makeTestableService(Google_Client $mockClient): object
    {
        return new class ('id', 'secret', $mockClient) extends GoogleDriveService {
            public function __construct(
                string $clientId,
                string $clientSecret,
                private readonly Google_Client $mockClient,
            ) {
                parent::__construct($clientId, $clientSecret);
            }

            protected function buildBaseClient(): Google_Client
            {
                return $this->mockClient;
            }

            public function testBuildClientFromCredentials(array $credentials, callable $cb): Google_Client
            {
                return $this->buildClientFromCredentials($credentials, $cb);
            }
        };
    }
}
