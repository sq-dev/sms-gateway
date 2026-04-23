<?php

declare(strict_types=1);

namespace SmsGateway\Contracts;

use SmsGateway\DTO\SendResult;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\Exception\ProviderException;

/**
 * Contract implemented by anything able to dispatch an SMS through some provider.
 *
 * Kept separate from {@see TracksSmsStatusInterface} so providers that only
 * expose a send endpoint can still plug into the library cleanly.
 */
interface SendsSmsInterface
{
    /**
     * Send the given message.
     *
     * @throws ProviderException When the provider rejects the request or its API is unreachable.
     *                           Adapters MUST translate transport/auth/quota/remote errors into
     *                           this exception type instead of letting vendor-specific exceptions
     *                           leak out.
     */
    public function send(SmsMessage $message): SendResult;
}
