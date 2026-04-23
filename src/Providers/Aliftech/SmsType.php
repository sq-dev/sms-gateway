<?php

declare(strict_types=1);

namespace SmsGateway\Providers\Aliftech;

/**
 * Aliftech `SmsType` codes documented in the HTTP API.
 *
 * Each case maps directly to the integer the gateway expects in the
 * `SmsType` body field. The enum is provider-specific on purpose - it lives
 * in the Aliftech namespace because it has no meaning for other adapters.
 *
 * @link https://docs.smsgate.tj/api/sms-api.html
 */
enum SmsType: int
{
    /** General-purpose informational messages. */
    case Common = 1;

    /** One-time passwords / 2FA codes. Aliftech routes these on a faster path. */
    case Otp = 2;

    /** Bulk / marketing campaigns. */
    case Batch = 3;
}
