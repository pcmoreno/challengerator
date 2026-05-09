<?php
declare(strict_types=1);

namespace App\Tests\Entity\Challenge;

use App\Entity\Challenge\Voter;
use PHPUnit\Framework\TestCase;

class VoterTest extends TestCase
{
    public function test_token_expiration_should_be_20_minutes_in_the_future(): void
    {
        $voter = Voter::createForChallenge('testVoter', 'testPass', 'testChallenge');
        $this->assertNull($voter->getTokenExpirationDate());
        $this->assertNull($voter->getToken());

        $voter->generateToken();
        $this->assertNotNull($voter->getToken());
        $this->assertTrue((new \DateTime())->getTimestamp() < $voter->getTokenExpirationDate());
        $this->assertEquals(1200, (new \DateTime())->setTimestamp($voter->getTokenExpirationDate())->getTimestamp() - (new \DateTime())->getTimestamp());
    }

    public function test_addCarsToSelf_throws_if_not_registered_for_challenge(): void
    {
        $voter = Voter::createForChallenge('Paulo', 'pass', 'challenge-A');

        $this->expectException(\Exception::class);
        $voter->addCarsToSelf(['car1', 'car2'], 'challenge-B', true);
    }

    public function test_addCarsToSelf_sets_cars_for_registered_challenge(): void
    {
        $voter = Voter::createForChallenge('Paulo', 'pass', 'challenge-A');
        $voter->addCarsToSelf(['car1', 'car2'], 'challenge-A', true);

        $this->assertSame(['car1', 'car2'], $voter->getUnvotedCarsForChallenge('challenge-A'));
    }

    public function test_setCarsToVotedForChallenge_moves_cars(): void
    {
        $voter = Voter::createForChallenge('Paulo', 'pass', 'challenge-A');
        $voter->addCarsToSelf(['car1', 'car2', 'car3'], 'challenge-A', true);

        $voter->setCarsToVotedForChallenge(['car1', 'car2'], 'challenge-A');

        $this->assertSame(['car3'], $voter->getUnvotedCarsForChallenge('challenge-A'));
        $this->assertContains('car1', $voter->getVotedCarsForChallenge('challenge-A'));
        $this->assertContains('car2', $voter->getVotedCarsForChallenge('challenge-A'));
    }

    public function test_isRegisteredForChallenge_returns_correct_value(): void
    {
        $voter = Voter::createForChallenge('Paulo', 'pass', 'challenge-A');

        $this->assertTrue($voter->isRegisteredForChallenge('challenge-A'));
        $this->assertFalse($voter->isRegisteredForChallenge('challenge-B'));
    }

    public function test_countCarsLeftToCompare_reflects_queue(): void
    {
        $voter = Voter::createForChallenge('Paulo', 'pass', 'rally');
        $voter->addCarsToSelf(['car1', 'car2', 'car3'], 'rally', true);

        $this->assertSame(3, $voter->countCarsLeftToCompareForChallenge('rally'));

        $voter->setCarsToVotedForChallenge(['car1'], 'rally');
        $this->assertSame(2, $voter->countCarsLeftToCompareForChallenge('rally'));
    }
}
