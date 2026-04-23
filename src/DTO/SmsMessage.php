<?php

declare(strict_types=1);

namespace SmsGateway\DTO;

use SmsGateway\Exception\InvalidMessageException;

/**
 * Immutable outbound SMS payload shared by every provider.
 *
 * The DTO only enforces the minimum invariants the library is sure about:
 *  - recipient must be non-empty,
 *  - text must be non-empty,
 *  - sender, if provided, must be non-empty.
 *
 * Format-level validation (E.164 phone numbers, alphanumeric sender-id rules,
 * length caps, encoding) is intentionally left to provider adapters because
 * those rules differ per operator/provider/country.
 */
final class SmsMessage
{
    /**
     * @param string               $to       Recipient address (usually MSISDN in E.164 format, but
     *                                       may also be a short code depending on the provider).
     * @param string               $text     Message body.
     * @param string|null          $from     Optional sender id / source address.
     * @param array<string, mixed> $metadata Provider-agnostic extras (flash, priority, DLR url,
     *                                       client reference, ...). Each provider adapter decides
     *                                       which keys it understands; unknown keys are ignored.
     */
    public function __construct(
        public readonly string $to,
        public readonly string $text,
        public readonly ?string $from = null,
        public readonly array $metadata = [],
    ) {
        if (trim($to) === '') {
            throw new InvalidMessageException('SMS recipient ("to") must not be empty.');
        }

        if ($text === '') {
            throw new InvalidMessageException('SMS text must not be empty.');
        }

        if ($from !== null && trim($from) === '') {
            throw new InvalidMessageException('SMS sender ("from") must be null or a non-empty string.');
        }
    }

    /**
     * Return a copy of this message with the provided metadata merged in.
     *
     * Existing keys are overwritten by the new payload. Use this to keep DTOs
     * truly immutable while still attaching provider hints later in a pipeline.
     *
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->to,
            $this->text,
            $this->from,
            array_replace($this->metadata, $metadata),
        );
    }
}
