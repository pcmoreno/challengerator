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
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $this->assertFalse($challenge->isActive());
    }

    public function test_create_sets_name_and_owner(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $this->assertSame('rally', $challenge->getName());
        $this->assertSame('secret', $challenge->getOwner());
    }

    public function test_addCarToChallenge_adds_car(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);
        $challenge->addCarToChallenge($car);
        $this->assertContains($car->getId(), $challenge->getCars());
    }

    public function test_addCarToChallenge_throws_on_duplicate(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);
        $challenge->addCarToChallenge($car);

        $this->expectException(\Exception::class);
        $challenge->addCarToChallenge($car);
    }

    public function test_addVoterToChallenge_throws_on_duplicate(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $voter = Voter::createForChallenge('Paulo', 'pass', 'rally');
        $challenge->addVoterToChallenge($voter);

        $this->expectException(\Exception::class);
        $challenge->addVoterToChallenge($voter);
    }

    public function test_removeCarFromChallenge_removes_car(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);
        $challenge->addCarToChallenge($car);
        $challenge->removeCarFromChallenge($car->getId());
        $this->assertNotContains($car->getId(), $challenge->getCars());
    }

    public function test_removeVoterFromChallenge_removes_voter(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $voter = Voter::createForChallenge('Paulo', 'pass', 'rally');
        $challenge->addVoterToChallenge($voter);
        $challenge->removeVoterFromChallenge($voter->getId());
        $this->assertFalse($challenge->hasVoter($voter->getId()));
    }

    public function test_hasVoter_returns_correct_value(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $voter = Voter::createForChallenge('Paulo', 'pass', 'rally');

        $this->assertFalse($challenge->hasVoter($voter->getId()));
        $challenge->addVoterToChallenge($voter);
        $this->assertTrue($challenge->hasVoter($voter->getId()));
    }

    public function test_activate_sets_active(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $challenge->activate();
        $this->assertTrue($challenge->isActive());
    }

    public function test_allowsSelfRegistration_defaults_to_false(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $this->assertFalse($challenge->allowsSelfRegistration());
    }

    public function test_toggleSelfRegistration_enables_and_returns_code(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $code = $challenge->toggleSelfRegistration();

        $this->assertTrue($challenge->allowsSelfRegistration());
        $this->assertNotEmpty($code);
        $this->assertSame($code, $challenge->getSelfRegistrationCode());
    }

    public function test_toggleSelfRegistration_disables_on_second_call(): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'secret');
        $challenge->toggleSelfRegistration();
        $challenge->toggleSelfRegistration();

        $this->assertFalse($challenge->allowsSelfRegistration());
    }

    public function test_fromArray_roundtrips_all_fields(): void
    {
        $expiry = time() + 9999;
        $data = [
            'id'                    => 'info',
            'name'                  => 'rally',
            'cars'                  => ['car-1', 'car-2'],
            'voters'                => ['42', '99'],
            'isActive'              => true,
            'owner'                 => 'hashed-secret',
            'allowSelfRegistration' => true,
            'selfRegistrationCode'  => 'code-xyz',
        ];

        $challenge = Challenge::fromArray($data);

        $this->assertSame('rally', $challenge->getName());
        $this->assertSame('hashed-secret', $challenge->getOwner());
        $this->assertTrue($challenge->isActive());
        $this->assertSame(['car-1', 'car-2'], $challenge->getCars());
        $this->assertSame(['42', '99'], $challenge->getVoters());
        $this->assertTrue($challenge->allowsSelfRegistration());
        $this->assertSame('code-xyz', $challenge->getSelfRegistrationCode());
    }

}
