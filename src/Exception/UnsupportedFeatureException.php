<?php

declare(strict_types=1);

namespace SmsGateway\Exception;

use LogicException;

/**
 * Thrown when consumer code asks a provider to perform an action its contract
 * does not support (for example, status tracking on a send-only provider).
 *
 * Providers are encouraged to expose their capabilities via dedicated interfaces
 * so this exception is needed only as a safety net for dynamic lookups.
 */
final class UnsupportedFeatureException extends LogicException implements SmsGatewayException
{
}
