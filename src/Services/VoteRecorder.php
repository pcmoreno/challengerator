<?php
declare(strict_types=1);

namespace App\Services;

use App\Message\LogVoteMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final class VoteRecorder
{
    public function __construct(private readonly MessageBusInterface $messageBus) {}

    public function record(LogVoteMessage $message): void
    {
        $this->messageBus->dispatch($message);
    }
}
