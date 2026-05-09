<?php
declare(strict_types=1);

namespace App\Entity\Doctrine;

use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(
 *     name="voter_car_queue",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="uq_voter_challenge_car", columns={"user_id", "challenge_id", "car_id"})
 *     }
 * )
 */
class DbVoterCarQueue
{
    const STATUS_PENDING = 'pending';
    const STATUS_VOTED   = 'voted';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private User $user;

    /**
     * @ORM\ManyToOne(targetEntity=DbChallenge::class)
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private DbChallenge $challenge;

    /**
     * @ORM\ManyToOne(targetEntity=DbCar::class)
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private DbCar $car;

    /**
     * @ORM\Column(type="string", length=10, options={"default": "pending"})
     */
    private string $status = self::STATUS_PENDING;

    public function __construct(User $user, DbChallenge $challenge, DbCar $car)
    {
        $this->user = $user;
        $this->challenge = $challenge;
        $this->car = $car;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getChallenge(): DbChallenge
    {
        return $this->challenge;
    }

    public function getCar(): DbCar
    {
        return $this->car;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function markVoted(): void
    {
        $this->status = self::STATUS_VOTED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
