<?php
declare(strict_types=1);

namespace App\Tests\Entity\Challenge;

use App\Entity\Challenge\Car;
use App\Entity\Challenge\Challenge;
use App\Entity\Challenge\Voter;
use PHPUnit\Framework\TestCase;

class ChallengeTest extends TestCase
{
    public function test_create_sets_inactive_state(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $this->assertFalse($challenge->isActive());
    }

    public function test_create_sets_name_and_owner(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $this->assertSame('rally', $challenge->getName());
        $this->assertSame('secret', $challenge->getOwner());
    }

    public function test_addCarToChallenge_adds_car(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);
        $challenge->addCarToChallenge($car);
        $this->assertContains($car->getId(), $challenge->getCars());
    }

    public function test_addCarToChallenge_throws_on_duplicate(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);
        $challenge->addCarToChallenge($car);

        $this->expectException(\Exception::class);
        $challenge->addCarToChallenge($car);
    }

    public function test_addVoterToChallenge_throws_on_duplicate(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $voter = Voter::createForChallenge('Paulo', 'pass', 'rally');
        $challenge->addVoterToChallenge($voter);

        $this->expectException(\Exception::class);
        $challenge->addVoterToChallenge($voter);
    }

    public function test_removeCarFromChallenge_removes_car(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);
        $challenge->addCarToChallenge($car);
        $challenge->removeCarFromChallenge($car->getId());
        $this->assertNotContains($car->getId(), $challenge->getCars());
    }

    public function test_removeVoterFromChallenge_removes_voter(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $voter = Voter::createForChallenge('Paulo', 'pass', 'rally');
        $challenge->addVoterToChallenge($voter);
        $challenge->removeVoterFromChallenge($voter->getId());
        $this->assertFalse($challenge->hasVoter($voter->getId()));
    }

    public function test_hasVoter_returns_correct_value(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $voter = Voter::createForChallenge('Paulo', 'pass', 'rally');

        $this->assertFalse($challenge->hasVoter($voter->getId()));
        $challenge->addVoterToChallenge($voter);
        $this->assertTrue($challenge->hasVoter($voter->getId()));
    }

    public function test_activate_sets_active(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $challenge->activate();
        $this->assertTrue($challenge->isActive());
    }

    public function test_getAdminToken_returns_null_before_generation(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $this->assertNull($challenge->getAdminToken());
    }

    public function test_generateAdminToken_sets_non_null_token(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $challenge->generateAdminToken();
        $this->assertNotNull($challenge->getAdminToken());
    }

    public function test_getAdminToken_returns_null_for_expired_token(): void
    {
        $challenge = Challenge::fromArray([
            'id'                       => 'info',
            'name'                     => 'rally',
            'cars'                     => [],
            'voters'                   => [],
            'isActive'                 => false,
            'owner'                    => 'secret',
            'adminToken'               => 'expired-token',
            'adminTokenExpirationDate' => time() - 1,
        ]);
        $this->assertNull($challenge->getAdminToken());
    }

    public function test_allowsSelfRegistration_defaults_to_false(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $this->assertFalse($challenge->allowsSelfRegistration());
    }

    public function test_toggleSelfRegistration_enables_and_returns_code(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $code = $challenge->toggleSelfRegistration();

        $this->assertTrue($challenge->allowsSelfRegistration());
        $this->assertNotEmpty($code);
        $this->assertSame($code, $challenge->getSelfRegistrationCode());
    }

    public function test_toggleSelfRegistration_disables_on_second_call(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $challenge->toggleSelfRegistration();
        $challenge->toggleSelfRegistration();

        $this->assertFalse($challenge->allowsSelfRegistration());
    }

    public function test_toCouchDocument_roundtrip_via_fromCouchDocument(): void
    {
        $challenge = Challenge::create('rally', 'secret');
        $challenge->generateAdminToken();

        $doc = json_decode(json_encode($challenge->toCouchDocument()), true);
        $doc['_rev'] = '1-abc';
        $restored = Challenge::fromCouchDocument($doc);

        $this->assertSame('rally', $restored->getName());
        $this->assertSame('secret', $restored->getOwner());
        $this->assertFalse($restored->isActive());
    }
}
