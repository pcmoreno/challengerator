<?php

namespace App\Tests\Services;

use App\Entity\Challenge\Outcome;
use App\Entity\Challenge\Rating;
use App\Services\RatingService;
use PHPUnit\Framework\TestCase;

class RatingServiceTest extends TestCase
{
    public function test_calculate_expected_results_for_rating() {
        $this->assertEquals(
            0.5,
            RatingService::getExpectedWinsForPlayer(1500,1500)
        );

        $this->assertTrue(0.75 < RatingService::getExpectedWinsForPlayer(2000,1500));
        $this->assertTrue(0.75 > RatingService::getExpectedWinsForPlayer(1500,1999));
    }

    public function test_it_should_calculate_new_rating_based_on_result()
    {
        $this->assertTrue(
            RatingService::getNewRating(1500, 1500, 0.5) === 1500
        );

        $this->assertTrue(
            RatingService::getNewRating(1500, 1500, 1) > 1500
        );

        $this->assertTrue(
            RatingService::getNewRating(1500, 1500, 0) < 1500
        );
    }

    public function test_it_should_compare_and_adjust_ratings_correctly_for_a_draw()
    {
        $originalRatingB = Rating::createNew();
        $ratingA = Rating::createNew();
        $ratingB = Rating::createNew();

        // should stay the same for a draw and equal rating
        RatingService::compareAndAdjust($ratingA, $ratingB, Outcome::DRAW);
        $this->assertEquals($ratingA->getRating(), $ratingB->getRating());

        // in case of different Ratings and a draw, the lower should increase and the higher decrease
        $ratingA->setRating(1400);
        RatingService::compareAndAdjust($ratingA, $ratingB, Outcome::DRAW);
        $this->assertTrue($ratingA->getRating() > 1400);
        $this->assertTrue($ratingB->getRating() < $originalRatingB->getRating());
    }

    public function test_it_should_compare_and_adjust_ratings_correctly_for_a_win_or_loss()
    {
        $originalRating = Rating::createNew();
        $ratingA = Rating::createNew();
        $ratingB = Rating::createNew();

        // LEFT SIDE WINS
        RatingService::compareAndAdjust($ratingA, $ratingB, Outcome::LEFT_WINS);
        // it changes the ratings
        $this->assertNotEquals($ratingA->getRating(), $ratingB->getRating());
        // the winner's rating is higher than the loser
        $this->assertTrue($ratingA->getRating() > $ratingB->getRating());
        // the rating gained is equal to the rating lost
        $this->assertEquals($originalRating->getRating() - $ratingA->getRating(), $ratingB->getRating() - $originalRating->getRating());

        // RIGHT SIDE WINS
        RatingService::compareAndAdjust($ratingA, $ratingB, Outcome::RIGHT_WINS);
        // it changes the ratings
        $this->assertNotEquals($ratingA->getRating(), $ratingB->getRating());
        // the winner's rating is higher than the loser
        $this->assertTrue($ratingA->getRating() < $ratingB->getRating());
        // the rating gained is equal to the rating lost
        $this->assertEquals($originalRating->getRating() - $ratingA->getRating(), $ratingB->getRating() - $originalRating->getRating());
    }
}
