<?php
declare(strict_types=1);

namespace App\Tests\Entity\Challenge;

use App\Entity\Challenge\Outcome;
use PHPUnit\Framework\TestCase;

class OutcomeTest extends TestCase
{
    public function test_create_throws_on_invalid_outcome(): void
    {
        $this->expectException(\Exception::class);
        Outcome::create('invalid');
    }

    public function test_create_accepts_left(): void
    {
        $outcome = Outcome::create(Outcome::LEFT_WINS);
        $this->assertSame(Outcome::LEFT_WINS, $outcome->getOutcome());
    }

    public function test_create_accepts_right(): void
    {
        $outcome = Outcome::create(Outcome::RIGHT_WINS);
        $this->assertSame(Outcome::RIGHT_WINS, $outcome->getOutcome());
    }

    public function test_create_accepts_draw(): void
    {
        $outcome = Outcome::create(Outcome::DRAW);
        $this->assertSame(Outcome::DRAW, $outcome->getOutcome());
    }
}
