<?php

namespace App\Tests\Entity\Challenge;

use App\Entity\Challenge\Voter;
use PHPUnit\Framework\TestCase;

class VoterTest extends TestCase
{

    public function test_token_expiration_should_be_20_minutes_in_the_future()
    {
        $voter = Voter::createForChallenge('testVoter', 'testPass', 'testChallenge');
        $this->assertNull($voter->getTokenExpirationDate());
        $this->assertNull($voter->getToken());

        $voter->generateToken();
        $this->assertNotNull($voter->getToken());

        $this->assertTrue((new \DateTime())->getTimestamp() < $voter->getTokenExpirationDate());
        // 1200 === 20 minutes
        $this->assertEquals(1200, (new \DateTime())->setTimestamp($voter->getTokenExpirationDate())->getTimestamp() - (new \DateTime())->getTimestamp());
    }
}
