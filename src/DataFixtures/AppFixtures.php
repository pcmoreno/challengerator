<?php
declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Auth\User;
use App\Entity\Challenge\Car;
use App\Entity\Challenge\Challenge;
use App\Entity\Challenge\Voter;
use App\Repository\CarRepositoryInterface;
use App\Repository\ChallengeRepositoryInterface;
use App\Repository\VoterRepositoryInterface;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        private ChallengeRepositoryInterface $challenges,
        private CarRepositoryInterface $cars,
        private VoterRepositoryInterface $voters,
        private EntityManagerInterface $em,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Super-admin
        $super = new User('superadmin');
        $super->setPassword($this->hasher->hashPassword($super, 'superadmin'));
        $super->addRole('ROLE_SUPER_ADMIN');
        $super->verify();
        $manager->persist($super);
        $manager->flush();

        // Challenge (admin password: adminpass)
        $adminPassHash = password_hash('adminpass', PASSWORD_BCRYPT);
        $challenge = Challenge::create('dev-rally', $adminPassHash);
        $this->challenges->create('dev-rally');
        $this->challenges->save($challenge);

        // Cars (placeholder Google Drive IDs)
        $carData = [
            ['name' => 'Ford Mustang',     'a' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs', 'b' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs'],
            ['name' => 'Dodge Challenger', 'a' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs', 'b' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs'],
            ['name' => 'Chevrolet Camaro', 'a' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs', 'b' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs'],
            ['name' => 'BMW M3',           'a' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs', 'b' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs'],
            ['name' => 'Porsche 911',      'a' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs', 'b' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs'],
        ];

        $carIds = [];
        foreach ($carData as $data) {
            $car = Car::create([
                'name'        => $data['name'],
                'imageUrlA'   => $data['a'],
                'imageUrlB'   => $data['b'],
                'challengeId' => 'dev-rally',
            ]);
            $this->cars->save($car);
            $challenge->addCarToChallenge($car);
            $carIds[] = $car->getId();
        }
        $this->challenges->save($challenge);

        // Voters (alice / alice123, bob / bob123)
        foreach (['alice' => 'alice123', 'bob' => 'bob123'] as $name => $pass) {
            $voter = Voter::createForChallenge($name, $pass, 'dev-rally');
            $this->voters->save($voter);
            $challenge->addVoterToChallenge($voter);
        }
        $this->challenges->save($challenge);

        // Initialize voting queues so voters can vote immediately
        $challenge = $this->challenges->find('dev-rally');
        foreach ($this->voters->findMany($challenge->getVoters()) as $voter) {
            $voter->addCarsToSelf($carIds, 'dev-rally', true);
            $this->voters->save($voter);
        }
    }
}
