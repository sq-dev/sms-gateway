<?php

declare(strict_types=1);

namespace SmsGateway\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for runtime failures coming from an underlying SMS provider.
 *
 * Provider adapters should translate transport, authentication, quota, and
 * remote API errors into this exception (or a more specific subclass) so that
 * callers only ever need to catch library types.
 */
class ProviderException extends RuntimeException implements SmsGatewayException
{
    /**
     * @param string         $message       Human-readable failure description.
     * @param string|null    $providerName  Identifier of the provider that produced the failure.
     * @param string|null    $providerCode  Raw error code from the provider, if any.
     * @param Throwable|null $previous      Underlying transport/client exception, if any.
     */
    public function __construct(
        string $message,
        private readonly ?string $providerName = null,
        private readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getProviderName(): ?string
    {
        return $this->providerName;
    }

    public function getProviderCode(): ?string
    {
        return $this->providerCode;
    }
}
