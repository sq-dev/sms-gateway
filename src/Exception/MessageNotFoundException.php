<?php

declare(strict_types=1);

namespace SmsGateway\Exception;

/**
 * Thrown by status-tracking providers when the requested message id does not
 * exist on the provider side (expired, never sent through this account, ...).
 */
final class MessageNotFoundException extends ProviderException
{
}
