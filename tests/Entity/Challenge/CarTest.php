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

    public function test_fromCouchData_maps_fields_correctly(): void
    {
        $data = [
            '_id' => 'car-uuid',
            'name' => 'Camaro',
            'rating' => 1600,
            'imageUrlA' => 'urlA',
            'imageUrlB' => 'urlB',
            'addedOn' => ['date' => '2024-01-01 00:00:00.000000'],
            'challengeId' => 'challenge1',
        ];
        $car = Car::fromCouchData($data);

        $this->assertSame('car-uuid', $car->getId());
        $this->assertSame('Camaro', $car->getName());
        $this->assertSame(1600, $car->getRating()->getRating());
        $this->assertSame('urlA', $car->getImageUrlA());
        $this->assertSame('urlB', $car->getImageUrlB());
        $this->assertSame('challenge1', $car->getChallengeId());
    }

    public function test_toCouchDocument_contains_required_fields(): void
    {
        $car = Car::create(['name' => 'Mustang', 'imageUrlA' => 'a', 'imageUrlB' => 'b', 'challengeId' => 'c1']);
        $doc = $car->toCouchDocument();

        $this->assertSame($car->getId(), $doc->_id);
        $this->assertSame('Mustang', $doc->name);
        $this->assertSame(1500, $doc->rating);
        $this->assertSame('c1', $doc->challengeId);
    }
}
