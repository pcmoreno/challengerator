<?php
declare(strict_types=1);

namespace App\Repository\Doctrine;

use App\Entity\Doctrine\DbInviteCode;
use App\Repository\InviteCodeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineInviteCodeRepository implements InviteCodeRepositoryInterface
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    private function em(): EntityManagerInterface
    {
        return $this->registry->getManager();
    }

    public function validateAndConsume(string $code): bool
    {
        $inviteCode = $this->em()->getRepository(DbInviteCode::class)->findOneBy([
            'code' => $code,
            'usedAt' => null,
        ]);

        if ($inviteCode === null) {
            return false;
        }

        $inviteCode->consume();
        $this->em()->flush();

        return true;
    }
}
