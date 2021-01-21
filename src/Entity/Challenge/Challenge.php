<?php
declare(strict_types=1);

namespace App\Entity\Challenge;

class Challenge
{
    private string $id;
    private array $cars;
    private array $voters;
    private bool $isActive;
}