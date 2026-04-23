<?php

declare(strict_types=1);

namespace SmsGateway\Exception;

use InvalidArgumentException;

/**
 * Thrown when an SMS DTO is constructed with values that violate the shared contract
 * (empty recipient, empty text, blank sender, ...).
 *
 * This is a client-side validation error: it indicates the caller built an invalid
 * message, not that a provider rejected it.
 */
final class InvalidMessageException extends InvalidArgumentException implements SmsGatewayException
{
}
