<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Fixtures;

use DateTimeImmutable;
use SmsGateway\Contracts\SmsProviderInterface;
use SmsGateway\DTO\SendResult;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\DTO\StatusResult;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\MessageNotFoundException;
use SmsGateway\Exception\ProviderException;

/**
 * In-memory provider used to prove the shared contracts are usable end-to-end
 * without depending on a real HTTP backend.
 *
 * It is also the canonical reference implementation for anyone authoring a
 * custom provider: replace the array storage with real API calls and everything
 * else stays the same.
 */
final class DummyProvider implements SmsProviderInterface
{
    /** @var array<string, StatusResult> */
    private array $storage = [];

    private int $sequence = 0;

    /**
     * Messages passed to {@see self::send()}, in call order. Exposed for
     * observability so tests that wrap the provider through a facade can
     * assert how the facade mapped its arguments into a {@see SmsMessage}.
     *
     * @var list<SmsMessage>
     */
    public array $sentMessages = [];

    public function __construct(
        private readonly string $name = 'dummy',
        private readonly MessageStatus $initialStatus = MessageStatus::Queued,
        private readonly bool $failNextSend = false,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function send(SmsMessage $message): SendResult
    {
        if ($this->failNextSend) {
            throw new ProviderException('Simulated provider failure.', $this->name, 'SIMULATED');
        }

        $this->sentMessages[] = $message;

        $this->sequence++;
        $messageId = sprintf('%s-%06d', $this->name, $this->sequence);

        $this->storage[$messageId] = new StatusResult(
            messageId: $messageId,
            status: $this->initialStatus,
            providerName: $this->name,
            updatedAt: new DateTimeImmutable('now'),
            raw: ['to' => $message->to, 'text' => $message->text],
        );

        return new SendResult(
            messageId: $messageId,
            status: $this->initialStatus,
            providerName: $this->name,
            raw: ['accepted' => true],
        );
    }

    public function getStatus(string $messageId): StatusResult
    {
        if (!isset($this->storage[$messageId])) {
            throw new MessageNotFoundException(
                sprintf('Message "%s" is not known to provider "%s".', $messageId, $this->name),
                $this->name,
                'NOT_FOUND',
            );
        }

        return $this->storage[$messageId];
    }

    /**
     * Mutate the stored status; used by tests to simulate lifecycle transitions.
     */
    public function transition(string $messageId, MessageStatus $status): void
    {
        $existing = $this->getStatus($messageId);

        $this->storage[$messageId] = new StatusResult(
            messageId: $existing->messageId,
            status: $status,
            providerName: $existing->providerName,
            updatedAt: new DateTimeImmutable('now'),
            raw: $existing->raw,
        );
    }
}
