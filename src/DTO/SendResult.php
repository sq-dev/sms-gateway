<?php

declare(strict_types=1);

namespace SmsGateway\DTO;

use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\InvalidMessageException;

/**
 * Immutable result of a successful send call.
 *
 * A `SendResult` means the provider accepted the request without raising a
 * {@see \SmsGateway\Exception\ProviderException}. The resulting {@see MessageStatus}
 * describes the state the provider reports right after acceptance - usually
 * {@see MessageStatus::Queued}, {@see MessageStatus::Sent}, or a terminal
 * rejection/failure state when the provider is synchronous.
 *
 * Transport-level or remote API failures must NOT be represented as a
 * `SendResult`; providers must throw a `ProviderException` instead.
 */
final class SendResult
{
    /**
     * @param string               $messageId    Provider-assigned identifier used for later status
     *                                           lookups. Must be non-empty.
     * @param MessageStatus        $status       Normalized status reported by the provider at
     *                                           acceptance time.
     * @param string               $providerName Identifier of the provider that handled the send.
     *                                           Must be non-empty.
     * @param array<string, mixed> $raw          Raw provider response payload, kept verbatim for
     *                                           debugging and auditing. Empty when unavailable.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly MessageStatus $status,
        public readonly string $providerName,
        public readonly array $raw = [],
    ) {
        if (trim($messageId) === '') {
            throw new InvalidMessageException('SendResult messageId must not be empty.');
        }

        if (trim($providerName) === '') {
            throw new InvalidMessageException('SendResult providerName must not be empty.');
        }
    }
}
