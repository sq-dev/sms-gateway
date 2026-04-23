<?php

declare(strict_types=1);

namespace SmsGateway\Enum;

/**
 * Normalized message lifecycle state shared across providers.
 *
 * Provider adapters translate their native status codes into one of these cases
 * so consumers can reason about delivery uniformly.
 */
enum MessageStatus: string
{
    /** Accepted by the provider, waiting to be dispatched. */
    case Queued = 'queued';

    /** Dispatched to the carrier network. */
    case Sent = 'sent';

    /** Delivery confirmed by the carrier. */
    case Delivered = 'delivered';

    /** Rejected by the provider before dispatch (invalid payload, auth, balance, ...). */
    case Rejected = 'rejected';

    /** Dispatched but the carrier could not deliver it. */
    case Undelivered = 'undelivered';

    /** Provider returned a permanent send failure. */
    case Failed = 'failed';

    /** TTL/validity window expired before delivery. */
    case Expired = 'expired';

    /** Provider cannot report a status for this message. */
    case Unknown = 'unknown';

    /**
     * Whether this status represents a terminal state that will not transition further.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Delivered,
            self::Rejected,
            self::Undelivered,
            self::Failed,
            self::Expired => true,
            self::Queued,
            self::Sent,
            self::Unknown => false,
        };
    }

    /**
     * Whether this status represents a successful delivery outcome.
     */
    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Sent,
            self::Delivered => true,
            default => false,
        };
    }
}
