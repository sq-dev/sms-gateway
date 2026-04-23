<?php

declare(strict_types=1);

namespace SmsGateway\Providers\Aliftech;

/**
 * Aliftech `Priority` codes documented in the HTTP API.
 *
 * Higher priority messages are processed first. {@see self::High} is
 * recommended for OTP traffic to minimize end-to-end latency.
 *
 * @link https://docs.smsgate.tj/api/sms-api.html
 */
enum SmsPriority: int
{
    case Low = 0;
    case Normal = 1;
    case High = 2;
}
