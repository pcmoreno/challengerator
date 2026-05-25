<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Vote\VoteLogEntry;
use App\Message\LogVoteMessage;
use App\Repository\VoteLogRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class LogVoteHandler
{
    public function __construct(
        private readonly VoteLogRepositoryInterface $voteLogRepository,
    ) {
    }

    public function __invoke(LogVoteMessage $message): void
    {
        $this->voteLogRepository->ensureDatabaseForChallenge($message->challengeName);
        $this->voteLogRepository->logVote(VoteLogEntry::fromMessage($message));
    }
}
