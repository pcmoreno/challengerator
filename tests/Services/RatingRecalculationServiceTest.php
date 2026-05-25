<?php
declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\Challenge\Car;
use App\Entity\Challenge\Challenge;
use App\Entity\Challenge\Rating;
use App\Entity\Vote\VoteLogEntry;
use App\Services\RatingRecalculationService;
use App\Services\RatingService;
use App\Tests\Repository\InMemory\InMemoryCarRepository;
use App\Tests\Repository\InMemory\InMemoryChallengeRepository;
use App\Tests\Repository\InMemory\InMemoryTransaction;
use App\Tests\Repository\InMemory\InMemoryVoteLogRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RatingRecalculationServiceTest extends TestCase
{
    private InMemoryChallengeRepository $challenges;
    private InMemoryCarRepository $cars;
    private InMemoryVoteLogRepository $voteLog;
    private RatingRecalculationService $service;

    protected function setUp(): void
    {
        $this->challenges = new InMemoryChallengeRepository();
        $this->cars = new InMemoryCarRepository();
        $this->voteLog = new InMemoryVoteLogRepository();
        $this->service = new RatingRecalculationService(
            $this->challenges,
            $this->cars,
            $this->voteLog,
            new InMemoryTransaction(),
            new NullLogger(),
        );
    }

    public function test_recalculate_resets_cars_to_1500_when_no_valid_votes_exist(): void
    {
        $this->seedChallengeWithTwoCars($carA, $carB);
        $carA->setRating(Rating::fromInt(1800));
        $carB->setRating(Rating::fromInt(1200));
        $this->cars->save($carA);
        $this->cars->save($carB);

        $report = $this->service->recalculate('rally');

        $this->assertSame(2, $report->carsUpdated);
        $this->assertSame(0, $report->votesApplied);
        $this->assertSame(0, $report->votesSkipped);
        $this->assertSame(1500, $this->cars->find($carA->getId())->getRating()->getRating());
        $this->assertSame(1500, $this->cars->find($carB->getId())->getRating()->getRating());
    }

    public function test_recalculate_replays_valid_votes_in_chronological_order(): void
    {
        $this->seedChallengeWithTwoCars($carA, $carB);

        $this->logVote($carA, $carB, 'left', '2026-05-01T10:00:00+00:00');
        $this->logVote($carA, $carB, 'left', '2026-05-01T11:00:00+00:00');

        $expectedRatingA = Rating::fromInt(1500);
        $expectedRatingB = Rating::fromInt(1500);
        RatingService::compareAndAdjust($expectedRatingA, $expectedRatingB, \App\Entity\Challenge\Outcome::LeftWins);
        RatingService::compareAndAdjust($expectedRatingA, $expectedRatingB, \App\Entity\Challenge\Outcome::LeftWins);

        $report = $this->service->recalculate('rally');

        $this->assertSame(2, $report->votesApplied);
        $this->assertSame(0, $report->votesSkipped);
        $this->assertSame($expectedRatingA->getRating(), $this->cars->find($carA->getId())->getRating()->getRating());
        $this->assertSame($expectedRatingB->getRating(), $this->cars->find($carB->getId())->getRating()->getRating());
    }

    public function test_recalculate_ignores_invalidated_votes(): void
    {
        $this->seedChallengeWithTwoCars($carA, $carB);

        $invalidVoteId = $this->logVote($carA, $carB, 'left', '2026-05-01T10:00:00+00:00');
        $this->voteLog->invalidate('rally', $invalidVoteId, 'admin');
        $this->logVote($carA, $carB, 'right', '2026-05-01T11:00:00+00:00');

        $expectedA = Rating::fromInt(1500);
        $expectedB = Rating::fromInt(1500);
        RatingService::compareAndAdjust($expectedA, $expectedB, \App\Entity\Challenge\Outcome::RightWins);

        $report = $this->service->recalculate('rally');

        $this->assertSame(1, $report->votesApplied, 'invalidated vote must be skipped');
        $this->assertSame($expectedA->getRating(), $this->cars->find($carA->getId())->getRating()->getRating());
        $this->assertSame($expectedB->getRating(), $this->cars->find($carB->getId())->getRating()->getRating());
    }

    public function test_recalculate_skips_votes_for_deleted_cars(): void
    {
        $this->seedChallengeWithTwoCars($carA, $carB);
        $this->logVote($carA, $carB, 'left', '2026-05-01T10:00:00+00:00');

        // Simulate a vote referencing a car that's no longer in the challenge.
        $ghostEntry = new VoteLogEntry(
            voteId: 'ghost-vote',
            challengeName: 'rally',
            voterId: 'voter-1',
            voterName: 'Paulo',
            carAId: 'gone-id',
            carAName: 'Deleted',
            carARatingBefore: 1500,
            carBId: $carB->getId(),
            carBName: $carB->getName(),
            carBRatingBefore: 1500,
            outcome: 'left',
            votedAt: new \DateTimeImmutable('2026-05-01T12:00:00+00:00'),
        );
        $this->voteLog->logVote($ghostEntry);

        $report = $this->service->recalculate('rally');

        $this->assertSame(1, $report->votesApplied);
        $this->assertSame(1, $report->votesSkipped);
    }

    public function test_recalculate_is_idempotent(): void
    {
        $this->seedChallengeWithTwoCars($carA, $carB);
        $this->logVote($carA, $carB, 'left', '2026-05-01T10:00:00+00:00');

        $first = $this->service->recalculate('rally');
        $firstRatingA = $this->cars->find($carA->getId())->getRating()->getRating();
        $firstRatingB = $this->cars->find($carB->getId())->getRating()->getRating();

        $second = $this->service->recalculate('rally');
        $secondRatingA = $this->cars->find($carA->getId())->getRating()->getRating();
        $secondRatingB = $this->cars->find($carB->getId())->getRating()->getRating();

        $this->assertSame($first->votesApplied, $second->votesApplied);
        $this->assertSame($firstRatingA, $secondRatingA);
        $this->assertSame($firstRatingB, $secondRatingB);
    }

    private function seedChallengeWithTwoCars(?Car &$carA, ?Car &$carB): void
    {
        $challenge = Challenge::create('rally', 'Rally', 'owner');
        $carA = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);
        $carB = Car::create(['name' => 'Camaro', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'rally']);
        $challenge->addCarToChallenge($carA);
        $challenge->addCarToChallenge($carB);
        $this->challenges->create('rally');
        $this->challenges->save($challenge);
        $this->cars->save($carA);
        $this->cars->save($carB);
    }

    private function logVote(Car $carA, Car $carB, string $outcome, string $iso8601): string
    {
        $voteId = bin2hex(random_bytes(8));
        $entry = new VoteLogEntry(
            voteId: $voteId,
            challengeName: 'rally',
            voterId: 'voter-1',
            voterName: 'Paulo',
            carAId: $carA->getId(),
            carAName: $carA->getName(),
            carARatingBefore: 1500,
            carBId: $carB->getId(),
            carBName: $carB->getName(),
            carBRatingBefore: 1500,
            outcome: $outcome,
            votedAt: new \DateTimeImmutable($iso8601),
        );
        $this->voteLog->logVote($entry);
        return $voteId;
    }
}
