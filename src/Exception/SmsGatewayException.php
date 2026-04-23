<?php

declare(strict_types=1);

namespace SmsGateway\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception thrown by this library.
 *
 * Catch this type to handle any SMS gateway failure uniformly without coupling
 * to provider-specific exception hierarchies.
 */
interface SmsGatewayException extends Throwable
{
}
