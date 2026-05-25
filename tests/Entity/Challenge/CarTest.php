<?php
declare(strict_types=1);

namespace App\Tests\Entity\Challenge;

use App\Entity\Challenge\Car;
use PHPUnit\Framework\TestCase;

class CarTest extends TestCase
{
    public function test_create_generates_uuid_when_no_id_provided(): void
    {
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'c1']);
        $this->assertNotEmpty($car->getId());
    }

    public function test_create_uses_provided_id(): void
    {
        $car = Car::create(['_id' => 'fixed-id', 'name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'c1']);
        $this->assertSame('fixed-id', $car->getId());
    }

    public function test_new_car_starts_at_rating_1500(): void
    {
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'c1']);
        $this->assertSame(1500, $car->getRating()->getRating());
    }

}
