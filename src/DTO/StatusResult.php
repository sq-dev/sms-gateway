<?php

declare(strict_types=1);

namespace SmsGateway\DTO;

use DateTimeImmutable;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\InvalidMessageException;

/**
 * Immutable snapshot of a message's state at lookup time.
 *
 * Each call to {@see \SmsGateway\Contracts\TracksSmsStatusInterface::getStatus()}
 * returns a fresh instance; the object does not reflect subsequent changes.
 */
final class StatusResult
{
    /**
     * @param string                 $messageId    Identifier originally returned by the provider on
     *                                             send. Must be non-empty.
     * @param MessageStatus          $status       Normalized lifecycle state.
     * @param string                 $providerName Identifier of the provider that produced this
     *                                             snapshot. Must be non-empty.
     * @param DateTimeImmutable|null $updatedAt    When the status transitioned to this value
     *                                             according to the provider, or null if unknown.
     * @param array<string, mixed>   $raw          Raw provider response payload. Empty when
     *                                             unavailable.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly MessageStatus $status,
        public readonly string $providerName,
        public readonly ?DateTimeImmutable $updatedAt = null,
        public readonly array $raw = [],
    ) {
        if (trim($messageId) === '') {
            throw new InvalidMessageException('StatusResult messageId must not be empty.');
        }

        if (trim($providerName) === '') {
            throw new InvalidMessageException('StatusResult providerName must not be empty.');
        }
    }
}
