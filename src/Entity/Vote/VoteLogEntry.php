<?php
declare(strict_types=1);

namespace App\Entity\Vote;

use App\Message\LogVoteMessage;

final readonly class VoteLogEntry
{
    public function __construct(
        public string $voteId,
        public string $challengeName,
        public string $voterId,
        public string $voterName,
        public string $carAId,
        public string $carAName,
        public int $carARatingBefore,
        public string $carBId,
        public string $carBName,
        public int $carBRatingBefore,
        public string $outcome,
        public \DateTimeImmutable $votedAt,
        public string $status = 'valid',
        public ?\DateTimeImmutable $invalidatedAt = null,
        public ?string $invalidatedBy = null,
        public ?string $revision = null,
    ) {
    }

    public static function fromMessage(LogVoteMessage $message): self
    {
        return new self(
            voteId: $message->voteId,
            challengeName: $message->challengeName,
            voterId: $message->voterId,
            voterName: $message->voterName,
            carAId: $message->carAId,
            carAName: $message->carAName,
            carARatingBefore: $message->carARatingBefore,
            carBId: $message->carBId,
            carBName: $message->carBName,
            carBRatingBefore: $message->carBRatingBefore,
            outcome: $message->outcome,
            votedAt: new \DateTimeImmutable($message->votedAtIso8601),
        );
    }

    /**
     * @param array<string,mixed> $document
     */
    public static function fromCouchDocument(array $document): self
    {
        return new self(
            voteId: $document['_id'],
            challengeName: $document['challenge_name'],
            voterId: $document['voter']['id'],
            voterName: $document['voter']['name'],
            carAId: $document['car_a']['id'],
            carAName: $document['car_a']['name'],
            carARatingBefore: (int) $document['car_a']['rating_before'],
            carBId: $document['car_b']['id'],
            carBName: $document['car_b']['name'],
            carBRatingBefore: (int) $document['car_b']['rating_before'],
            outcome: $document['outcome'],
            votedAt: new \DateTimeImmutable($document['voted_at']),
            status: $document['status'] ?? 'valid',
            invalidatedAt: isset($document['invalidated_at']) ? new \DateTimeImmutable($document['invalidated_at']) : null,
            invalidatedBy: $document['invalidated_by'] ?? null,
            revision: $document['_rev'] ?? null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toCouchDocument(): array
    {
        $document = [
            '_id' => $this->voteId,
            'type' => 'vote',
            'schema_version' => 1,
            'challenge_name' => $this->challengeName,
            'voter' => [
                'id' => $this->voterId,
                'name' => $this->voterName,
            ],
            'car_a' => [
                'id' => $this->carAId,
                'name' => $this->carAName,
                'rating_before' => $this->carARatingBefore,
            ],
            'car_b' => [
                'id' => $this->carBId,
                'name' => $this->carBName,
                'rating_before' => $this->carBRatingBefore,
            ],
            'outcome' => $this->outcome,
            'voted_at' => $this->votedAt->format(\DateTimeInterface::ATOM),
            'status' => $this->status,
            'invalidated_at' => $this->invalidatedAt?->format(\DateTimeInterface::ATOM),
            'invalidated_by' => $this->invalidatedBy,
        ];

        if ($this->revision !== null) {
            $document['_rev'] = $this->revision;
        }

        return $document;
    }

    public function withInvalidation(\DateTimeImmutable $invalidatedAt, string $invalidatedBy): self
    {
        return new self(
            voteId: $this->voteId,
            challengeName: $this->challengeName,
            voterId: $this->voterId,
            voterName: $this->voterName,
            carAId: $this->carAId,
            carAName: $this->carAName,
            carARatingBefore: $this->carARatingBefore,
            carBId: $this->carBId,
            carBName: $this->carBName,
            carBRatingBefore: $this->carBRatingBefore,
            outcome: $this->outcome,
            votedAt: $this->votedAt,
            status: 'invalidated',
            invalidatedAt: $invalidatedAt,
            invalidatedBy: $invalidatedBy,
            revision: $this->revision,
        );
    }
}
