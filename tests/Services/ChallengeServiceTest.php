<?php
declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\Challenge\Car;
use App\Entity\Challenge\Challenge;
use App\Entity\Challenge\Voter;
use App\Exception\BusinessLogicException;
use App\Tests\Repository\InMemory\FlakyTransaction;
use App\Tests\Repository\InMemory\InMemoryCarRepository;
use App\Tests\Repository\InMemory\InMemoryChallengeRepository;
use App\Tests\Repository\InMemory\InMemoryInviteCodeRepository;
use App\Tests\Repository\InMemory\InMemoryTransaction;
use App\Tests\Repository\InMemory\InMemoryVoterRepository;
use App\Services\ChallengeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
        $this->service = new ChallengeService(
            $this->challenges,
            $this->cars,
            $this->voters,
            $this->codes,
            new InMemoryTransaction(),
            new NullLogger(),
            new NullLogger(),
        );
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

    // --- createNewChallenge ---

    public function test_createNewChallenge_with_valid_code_creates_challenge(): void
    {
        $this->service->createNewChallenge('rally', 'secret', 'VALID-CODE');
        $this->assertContains('rally', $this->challenges->listNames());
    }

    public function test_createNewChallenge_with_invalid_code_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->createNewChallenge('rally', 'secret', 'WRONG-CODE');
    }

    public function test_createNewChallenge_consumes_the_code(): void
    {
        $this->service->createNewChallenge('rally', 'secret', 'VALID-CODE');
        $this->expectException(\DomainException::class);
        $this->service->createNewChallenge('rally2', 'secret', 'VALID-CODE');
    }

    // --- addCar / getCarsForChallenge ---

    public function test_addCar_adds_car_to_challenge(): void
    {
        $this->makeChallenge();
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);

        $this->service->addCar('rally', $car);

        $cars = $this->service->getCarsForChallenge('rally');
        $this->assertCount(1, $cars);
        $this->assertSame('Mustang', $cars[0]->getName());
    }

    // --- getTwoCarsToBeVotedByUser ---

    public function test_getTwoCars_returns_two_when_queue_has_enough(): void
    {
        $challenge = $this->makeChallenge();
        $voter = $this->makeVoter();
        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $car3 = $this->makeCar();

        $voter->addCarsToSelf([$car1->getId(), $car2->getId(), $car3->getId()], 'rally');
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
        $voter->addCarsToSelf([$car->getId()], 'rally');
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
        $voter->addCarsToSelf([$car1->getId(), $car2->getId()], 'rally');
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
        $voter->addCarsToSelf([$car1->getId(), $car2->getId()], 'rally');
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
        $voter->addCarsToSelf([$car1->getId(), $car2->getId()], 'rally');
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
        $voter->addCarsToSelf([$car1->getId(), $car2->getId()], 'rally');
        $voter->setCarsToVotedForChallenge([$car1->getId()], 'rally');
        $this->voters->save($voter);

        $this->expectException(\Exception::class);
        $carParam = $car1->getId() . 'XXX' . $car2->getId();
        $this->service->voteOnCars($carParam, 'left', 'rally', $voter->getId());
    }

    public function test_voteOnCars_throws_when_same_car_submitted_twice(): void
    {
        $this->makeChallenge();
        $voter = $this->makeVoter();
        $car = $this->makeCar();
        $voter->addCarsToSelf([$car->getId()], 'rally');
        $this->voters->save($voter);

        $this->expectException(\App\Exception\BusinessLogicException::class);
        $this->service->voteOnCars($car->getId() . 'XXX' . $car->getId(), 'left', 'rally', $voter->getId());
    }

    public function test_voteOnCars_throws_on_invalid_result_string(): void
    {
        $this->makeChallenge();
        $voter = $this->makeVoter();
        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $voter->addCarsToSelf([$car1->getId(), $car2->getId()], 'rally');
        $this->voters->save($voter);

        $this->expectException(\InvalidArgumentException::class);
        $carParam = $car1->getId() . 'XXX' . $car2->getId();
        $this->service->voteOnCars($carParam, 'banana', 'rally', $voter->getId());
    }

    public function test_voteOnCars_throws_when_car_belongs_to_different_challenge(): void
    {
        $this->makeChallenge('rally');
        $this->makeChallenge('sprint');
        $voter = $this->makeVoter('rally');
        $carRally  = $this->makeCar('rally');
        $carSprint = $this->makeCar('sprint');

        $voter->addCarsToSelf([$carRally->getId(), $carSprint->getId()], 'rally');
        $this->voters->save($voter);

        $this->expectException(\InvalidArgumentException::class);
        $carParam = $carRally->getId() . 'XXX' . $carSprint->getId();
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

    public function test_addVoterFromIp_into_active_challenge_seeds_voter_queue(): void
    {
        $challenge = $this->makeChallenge();
        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $challenge->addCarToChallenge($car1);
        $challenge->addCarToChallenge($car2);
        $challenge->toggleSelfRegistration();
        $this->challenges->save($challenge);

        $this->service->initializeChallenge('rally');

        $result = $this->service->AddVoterToChallengeFromIp('rally', 'newvoter', 'pass', '1.2.3.4');

        $this->assertTrue($result);
        $voter = $this->voters->findByName('newvoter');
        $this->assertCount(2, $voter->getUnvotedCarsForChallenge('rally'));
    }

    public function test_addVoterFromIp_same_ip_different_challenge_is_allowed(): void
    {
        $rally = $this->makeChallenge('rally');
        $rally->toggleSelfRegistration();
        $this->challenges->save($rally);

        $sprint = $this->makeChallenge('sprint');
        $sprint->toggleSelfRegistration();
        $this->challenges->save($sprint);

        $this->service->AddVoterToChallengeFromIp('rally', 'voter1', 'pass', '1.2.3.4');
        $result = $this->service->AddVoterToChallengeFromIp('sprint', 'voter2', 'pass', '1.2.3.4');

        $this->assertTrue($result);
    }

    // --- initializeChallenge ---

    public function test_initializeChallenge_distributes_all_cars_to_voters_and_activates(): void
    {
        $this->makeChallenge();

        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $voter = $this->makeVoter();

        $challenge = $this->challenges->find('rally');
        $challenge->addCarToChallenge($car1);
        $challenge->addCarToChallenge($car2);
        $challenge->addVoterToChallenge($voter);
        $this->challenges->save($challenge);

        $this->service->initializeChallenge('rally');

        $this->assertTrue($this->challenges->find('rally')->isActive());

        $updatedVoter = $this->voters->find($voter->getId());
        $this->assertCount(2, $updatedVoter->getUnvotedCarsForChallenge('rally'));
    }

    public function test_initializeChallenge_with_zero_voters_still_activates(): void
    {
        $this->makeChallenge();

        $car1 = $this->makeCar();
        $car2 = $this->makeCar();
        $challenge = $this->challenges->find('rally');
        $challenge->addCarToChallenge($car1);
        $challenge->addCarToChallenge($car2);
        $this->challenges->save($challenge);

        $this->service->initializeChallenge('rally');

        $this->assertTrue($this->challenges->find('rally')->isActive());
    }

    public function test_initializeChallenge_with_zero_cars_still_activates(): void
    {
        $this->makeChallenge();
        $voter = $this->makeVoter();

        $challenge = $this->challenges->find('rally');
        $challenge->addVoterToChallenge($voter);
        $this->challenges->save($challenge);

        $this->service->initializeChallenge('rally');

        $this->assertTrue($this->challenges->find('rally')->isActive());
        $updatedVoter = $this->voters->find($voter->getId());
        $this->assertCount(0, $updatedVoter->getUnvotedCarsForChallenge('rally'));
    }

    // --- resetRoundOfVoteForUserOfChallenge ---

    public function test_resetRoundOfVote_throws_when_voter_not_in_challenge(): void
    {
        $this->makeChallenge('rally');
        $voter = $this->makeVoter('sprint');

        $this->expectException(\DomainException::class);
        $this->service->resetRoundOfVoteForUserOfChallenge('rally', $voter->getId());
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

    // --- voteOnCars retry ---

    public function test_voteOnCars_throws_BusinessLogicException_when_all_retries_exhausted(): void
    {
        $flakyService = new ChallengeService(
            $this->challenges,
            $this->cars,
            $this->voters,
            $this->codes,
            new FlakyTransaction(3),
            new NullLogger(),
            new NullLogger(),
        );

        $this->makeChallenge();
        $voter = $this->makeVoter();
        $carA  = $this->makeCar('car-a');
        $carB  = $this->makeCar('car-b');
        $voter->addCarsToSelf([$carA->getId(), $carB->getId()], 'rally');
        $this->voters->save($voter);

        $this->expectException(BusinessLogicException::class);
        $flakyService->voteOnCars($carA->getId() . 'XXX' . $carB->getId(), 'left', 'rally', $voter->getId());
    }
}
