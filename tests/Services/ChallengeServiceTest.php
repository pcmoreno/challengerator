<?php
declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\Auth\Role;
use App\Entity\Challenge\Car;
use App\Entity\Challenge\Challenge;
use App\Entity\Challenge\Voter;
use App\Repository\InMemory\InMemoryCarRepository;
use App\Repository\InMemory\InMemoryChallengeRepository;
use App\Repository\InMemory\InMemoryInviteCodeRepository;
use App\Repository\InMemory\InMemoryVoterRepository;
use App\Services\ChallengeService;
use PHPUnit\Framework\TestCase;

class ChallengeServiceTest extends TestCase
{
    private ChallengeService $service;
    private InMemoryChallengeRepository $challenges;
    private InMemoryCarRepository $cars;
    private InMemoryVoterRepository $voters;
    private InMemoryInviteCodeRepository $codes;

    protected function setUp(): void
    {
        $this->challenges = new InMemoryChallengeRepository();
        $this->cars = new InMemoryCarRepository();
        $this->voters = new InMemoryVoterRepository();
        $this->codes = new InMemoryInviteCodeRepository(['VALID-CODE']);
        $this->service = new ChallengeService($this->challenges, $this->cars, $this->voters, $this->codes);
    }

    // --- helpers ---

    private function makeChallenge(string $name = 'rally', string $owner = 'secret'): Challenge
    {
        $challenge = Challenge::create($name, $owner);
        $this->challenges->create($name);
        $this->challenges->save($challenge);
        return $challenge;
    }

    private function makeCar(string $challengeName = 'rally'): Car
    {
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => $challengeName]);
        $this->cars->save($car);
        return $car;
    }

    private function makeVoter(string $challengeName = 'rally', string $name = 'Paulo', string $pass = 'pass'): Voter
    {
        $voter = Voter::createForChallenge($name, $pass, $challengeName);
        $this->voters->save($voter);
        return $voter;
    }

    private function adminToken(Challenge $challenge): string
    {
        $challenge->generateAdminToken();
        $this->challenges->save($challenge);
        return $challenge->getAdminToken();
    }

    // --- createNewChallenge ---

    public function test_createNewChallenge_with_valid_code_creates_challenge(): void
    {
        $response = $this->service->createNewChallenge('rally', 'secret', 'VALID-CODE');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('rally', $this->challenges->listNames());
    }

    public function test_createNewChallenge_with_invalid_code_returns_403(): void
    {
        $response = $this->service->createNewChallenge('rally', 'secret', 'WRONG-CODE');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_createNewChallenge_consumes_the_code(): void
    {
        $this->service->createNewChallenge('rally', 'secret', 'VALID-CODE');
        $second = $this->service->createNewChallenge('rally2', 'secret', 'VALID-CODE');
        $this->assertSame(403, $second->getStatusCode());
    }

    // --- verifyLogin ---

    public function test_verifyLogin_admin_success(): void
    {
        $challenge = $this->makeChallenge('rally', 'adminpass');

        $login = (object)['user' => Role::ADMIN, 'pass' => 'adminpass'];
        [$role, $userId, $token] = $this->service->verifyLogin($login, 'rally');

        $this->assertSame(Role::ADMIN, $role);
        $this->assertNull($userId);
        $this->assertNotNull($token);
    }

    public function test_verifyLogin_admin_wrong_password_returns_none(): void
    {
        $this->makeChallenge('rally', 'adminpass');

        $login = (object)['user' => Role::ADMIN, 'pass' => 'wrong'];
        [$role] = $this->service->verifyLogin($login, 'rally');

        $this->assertSame(Role::NONE, $role);
    }

    public function test_verifyLogin_voter_success(): void
    {
        $challenge = $this->makeChallenge();
        $voter = $this->makeVoter('rally', 'Paulo', 'mypass');
        $challenge->addVoterToChallenge($voter);
        $this->challenges->save($challenge);

        $login = (object)['user' => 'Paulo', 'pass' => 'mypass'];
        [$role, $userId, $token] = $this->service->verifyLogin($login, 'rally');

        $this->assertSame(Role::VOTER, $role);
        $this->assertSame($voter->getId(), $userId);
        $this->assertNotNull($token);
    }

    public function test_verifyLogin_voter_of_different_challenge(): void
    {
        $this->makeChallenge('rally');
        $this->makeChallenge('sprint');
        $voter = $this->makeVoter('sprint', 'Paulo', 'mypass');
        $sprint = $this->challenges->find('sprint');
        $sprint->addVoterToChallenge($voter);
        $this->challenges->save($sprint);

        $login = (object)['user' => 'Paulo', 'pass' => 'mypass'];
        [$role] = $this->service->verifyLogin($login, 'rally');

        $this->assertSame(Role::VOTER_OF_A_DIFFERENT_CHALLENGE, $role);
    }

    public function test_verifyLogin_unknown_user_returns_none(): void
    {
        $this->makeChallenge();
        $login = (object)['user' => 'nobody', 'pass' => 'pass'];
        [$role] = $this->service->verifyLogin($login, 'rally');
        $this->assertSame(Role::NONE, $role);
    }

    // --- addCar / getCarsForChallenge ---

    public function test_addCar_adds_car_to_challenge(): void
    {
        $challenge = $this->makeChallenge();
        $token = $this->adminToken($challenge);
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);

        $this->service->addCar('rally', $car, $token);

        $cars = $this->service->getCarsForChallenge('rally');
        $this->assertCount(1, $cars);
        $this->assertSame('Mustang', $cars[0]->getName());
    }

    public function test_addCar_with_invalid_token_does_not_add(): void
    {
        $this->makeChallenge();
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);

        $this->service->addCar('rally', $car, 'bad-token');

        $this->assertCount(0, $this->service->getCarsForChallenge('rally'));
    }

    // --- getTwoCarsToBeVotedByUser ---

    public function test_getTwoCars_returns_two_when_queue_has_enough(): void
    {
        $challenge = $this->makeChallenge();
        $voter = $this->makeVoter();
        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $car3 = $this->makeCar();

        $voter->addCarsToSelf([$car1->getId(), $car2->getId(), $car3->getId()], 'rally', true);
        $this->voters->save($voter);

        [$selectedCars, $remaining] = $this->service->getTwoCarsToBeVotedByUser('rally', $voter->getId());

        $this->assertCount(2, $selectedCars);
        $this->assertCount(1, $remaining);
    }

    public function test_getTwoCars_returns_empty_arrays_when_queue_has_fewer_than_two(): void
    {
        $this->makeChallenge();
        $voter = $this->makeVoter();
        $car = $this->makeCar();
        $voter->addCarsToSelf([$car->getId()], 'rally', true);
        $this->voters->save($voter);

        [$selectedCars, $remaining] = $this->service->getTwoCarsToBeVotedByUser('rally', $voter->getId());

        $this->assertSame([], $selectedCars);
        $this->assertSame([], $remaining);
    }

    // --- voteOnCars ---

    public function test_voteOnCars_left_wins_increases_car_a_rating(): void
    {
        $this->makeChallenge();
        $voter = $this->makeVoter();
        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $voter->addCarsToSelf([$car1->getId(), $car2->getId()], 'rally', true);
        $this->voters->save($voter);

        $carParam = $car1->getId() . 'XXX' . $car2->getId();
        $this->service->voteOnCars($carParam, 'left', 'rally', $voter->getId());

        $this->assertGreaterThan(1500, $this->cars->find($car1->getId())->getRating()->getRating());
        $this->assertLessThan(1500, $this->cars->find($car2->getId())->getRating()->getRating());
    }

    public function test_voteOnCars_right_wins_increases_car_b_rating(): void
    {
        $this->makeChallenge();
        $voter = $this->makeVoter();
        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $voter->addCarsToSelf([$car1->getId(), $car2->getId()], 'rally', true);
        $this->voters->save($voter);

        $carParam = $car1->getId() . 'XXX' . $car2->getId();
        $this->service->voteOnCars($carParam, 'right', 'rally', $voter->getId());

        $this->assertLessThan(1500, $this->cars->find($car1->getId())->getRating()->getRating());
        $this->assertGreaterThan(1500, $this->cars->find($car2->getId())->getRating()->getRating());
    }

    public function test_voteOnCars_draw_keeps_equal_ratings_unchanged(): void
    {
        $this->makeChallenge();
        $voter = $this->makeVoter();
        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $voter->addCarsToSelf([$car1->getId(), $car2->getId()], 'rally', true);
        $this->voters->save($voter);

        $carParam = $car1->getId() . 'XXX' . $car2->getId();
        $this->service->voteOnCars($carParam, 'draw', 'rally', $voter->getId());

        $this->assertSame(1500, $this->cars->find($car1->getId())->getRating()->getRating());
        $this->assertSame(1500, $this->cars->find($car2->getId())->getRating()->getRating());
    }

    public function test_voteOnCars_throws_when_car_not_in_queue(): void
    {
        $this->makeChallenge();
        $voter = $this->makeVoter();
        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $voter->addCarsToSelf([$car1->getId(), $car2->getId()], 'rally', true);
        $voter->setCarsToVotedForChallenge([$car1->getId()], 'rally');
        $this->voters->save($voter);

        $this->expectException(\Exception::class);
        $carParam = $car1->getId() . 'XXX' . $car2->getId();
        $this->service->voteOnCars($carParam, 'left', 'rally', $voter->getId());
    }

    // --- AddVoterToChallengeFromIp ---

    public function test_addVoterFromIp_succeeds_for_new_ip(): void
    {
        $challenge = $this->makeChallenge();
        $challenge->toggleSelfRegistration();
        $this->challenges->save($challenge);

        $result = $this->service->AddVoterToChallengeFromIp('rally', 'newvoter', 'pass', '1.2.3.4');

        $this->assertTrue($result);
    }

    public function test_addVoterFromIp_fails_on_duplicate_ip_and_challenge(): void
    {
        $challenge = $this->makeChallenge();
        $challenge->toggleSelfRegistration();
        $this->challenges->save($challenge);

        $this->service->AddVoterToChallengeFromIp('rally', 'voter1', 'pass', '1.2.3.4');
        $result = $this->service->AddVoterToChallengeFromIp('rally', 'voter2', 'pass', '1.2.3.4');

        $this->assertFalse($result);
    }

    public function test_addVoterFromIp_fails_when_self_registration_disabled(): void
    {
        $this->makeChallenge();
        $result = $this->service->AddVoterToChallengeFromIp('rally', 'voter', 'pass', '1.2.3.4');
        $this->assertFalse($result);
    }

    // --- initializeChallenge ---

    public function test_initializeChallenge_with_invalid_token_returns_403(): void
    {
        $this->makeChallenge();
        $response = $this->service->initializeChallenge('rally', 'bad-token');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_initializeChallenge_distributes_all_cars_to_voters_and_activates(): void
    {
        $challenge = $this->makeChallenge();
        $token = $this->adminToken($challenge);

        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $voter = $this->makeVoter();

        $challenge = $this->challenges->find('rally');
        $challenge->addCarToChallenge($car1);
        $challenge->addCarToChallenge($car2);
        $challenge->addVoterToChallenge($voter);
        $this->challenges->save($challenge);

        $response = $this->service->initializeChallenge('rally', $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->challenges->find('rally')->isActive());

        $updatedVoter = $this->voters->find($voter->getId());
        $this->assertCount(2, $updatedVoter->getUnvotedCarsForChallenge('rally'));
    }

    // --- toggleSelfRegistration ---

    public function test_toggleSelfRegistration_enables_self_reg(): void
    {
        $this->makeChallenge();
        $this->service->toggleSelfRegistrationForChallenge('rally');
        $this->assertSame('active', $this->service->isChallengeOpenToSelfRegistration('rally'));
    }

    public function test_toggleSelfRegistration_generates_a_code(): void
    {
        $this->makeChallenge();
        $code = $this->service->toggleSelfRegistrationForChallenge('rally');
        $this->assertNotEmpty($code);
        $this->assertSame($code, $this->service->getSelfRegistrationCodeForChallenge('rally'));
    }
}
