<?php
declare(strict_types=1);

namespace App\Tests\Services;

use App\Services\CouchDbService;
use \Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPOnCouch\CouchClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

class CouchDbServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var m\Mock|CouchClient  */
    private $client;

    /** @var CouchDbService|m\Mock */
    private $service;

    public function setUp(): void
    {
        $this->client = m::mock('alias:PHPOnCouch\CouchClient');
        $this->service = m::mock(new CouchDbService('testDsn', 'testdb'));
    }

//    public function test_it_should_try_to_create_document_with_the_correct_data()
//    {
//        $response = $this->service->createDocument(null, ["a"=>"b"], $this->client);
//
//        $this->client->shouldHaveBeenCalled()
//            ->once()
//            ->withAnyArgs()
//        ;
//
//        $this->assertEquals(200, $response->getStatusCode());
//    }
}
