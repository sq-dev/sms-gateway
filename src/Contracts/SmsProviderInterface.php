<?php

declare(strict_types=1);

namespace SmsGateway\Contracts;

/**
 * Composite contract for the standard provider capability set (send + status).
 *
 * Consumers depending on this interface get a single type they can type-hint
 * against while still being able to downcast to the more granular contracts.
 *
 * Providers that only support a subset of capabilities should implement the
 * individual interfaces directly instead of this composite one, so the type
 * system keeps documenting which features are actually available.
 */
interface SmsProviderInterface extends SendsSmsInterface, TracksSmsStatusInterface
{
    /**
     * Stable, human-readable identifier of the provider (e.g. "osonsms", "twilio").
     *
     * Used for logging, result attribution, and provider selection. MUST be
     * deterministic for a given configuration and MUST NOT change at runtime.
     */
    public function getName(): string;
}
