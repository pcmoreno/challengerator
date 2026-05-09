<?php
declare(strict_types=1);

namespace App\Tests\Entity\Challenge;

use App\Entity\Challenge\Outcome;
use PHPUnit\Framework\TestCase;

class OutcomeTest extends TestCase
{
    public function test_from_throws_on_invalid_value(): void
    {
        $this->expectException(\ValueError::class);
        Outcome::from('invalid');
    }

    public function test_left_wins_has_correct_value(): void
    {
        $this->assertSame('left', Outcome::LeftWins->value);
    }

    public function test_right_wins_has_correct_value(): void
    {
        $this->assertSame('right', Outcome::RightWins->value);
    }

    public function test_draw_has_correct_value(): void
    {
        $this->assertSame('draw', Outcome::Draw->value);
    }

    public function test_from_resolves_known_values(): void
    {
        $this->assertSame(Outcome::LeftWins, Outcome::from('left'));
        $this->assertSame(Outcome::RightWins, Outcome::from('right'));
        $this->assertSame(Outcome::Draw, Outcome::from('draw'));
    }
}
