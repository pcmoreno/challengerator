<?php
declare(strict_types=1);

namespace App\Tests\Entity\Challenge;

use App\Entity\Challenge\RoundOfComparisons;
use PHPUnit\Framework\TestCase;

class RoundOfComparisonsTest extends TestCase
{
    public function test_has_returns_false_for_unknown_challenge(): void
    {
        $rounds = new RoundOfComparisons();
        $this->assertFalse($rounds->has('unknown'));
    }

    public function test_has_returns_true_after_setting_cars(): void
    {
        $rounds = new RoundOfComparisons();
        $rounds->setCarsToBeVotedForChallenge(['car1', 'car2'], 'myChallenge');
        $this->assertTrue($rounds->has('myChallenge'));
    }

    public function test_getCarsLeftToVote_returns_empty_for_unknown_challenge(): void
    {
        $rounds = new RoundOfComparisons();
        $this->assertSame([], $rounds->getCarsLeftToVoteForChallenge('unknown'));
    }

    public function test_getCarsLeftToVote_returns_set_cars(): void
    {
        $rounds = new RoundOfComparisons();
        $rounds->setCarsToBeVotedForChallenge(['car1', 'car2'], 'challenge');
        $this->assertSame(['car1', 'car2'], $rounds->getCarsLeftToVoteForChallenge('challenge'));
    }

    public function test_getCarsCompared_returns_empty_initially(): void
    {
        $rounds = new RoundOfComparisons();
        $rounds->setCarsToBeVotedForChallenge(['car1'], 'challenge');
        $this->assertSame([], $rounds->getCarsComparedForChallenge('challenge'));
    }

    public function test_moveCarFromNotVotedToCompared_moves_correctly(): void
    {
        $rounds = new RoundOfComparisons();
        $rounds->setCarsToBeVotedForChallenge(['car1', 'car2'], 'challenge');
        $rounds->setCarsAlreadyComparedForChallenge([], 'challenge');

        $rounds->moveCarFromNotVotedToComparedForChallenge('car1', 'challenge');

        $this->assertSame(['car2'], $rounds->getCarsLeftToVoteForChallenge('challenge'));
        $this->assertSame(['car1'], $rounds->getCarsComparedForChallenge('challenge'));
    }

    public function test_toArray_reflects_current_state(): void
    {
        $rounds = new RoundOfComparisons();
        $rounds->setCarsToBeVotedForChallenge(['car1'], 'challenge');
        $rounds->setCarsAlreadyComparedForChallenge(['car0'], 'challenge');

        $array = $rounds->toArray();
        $this->assertSame(['car1'], $array['challenge']['carsToVote']);
        $this->assertSame(['car0'], $array['challenge']['carsCompared']);
    }
}
