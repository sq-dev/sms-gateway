<?php

declare(strict_types=1);

namespace SmsGateway\Contracts;

use SmsGateway\DTO\StatusResult;
use SmsGateway\Exception\MessageNotFoundException;
use SmsGateway\Exception\ProviderException;

/**
 * Contract implemented by providers that can report a message's lifecycle state.
 *
 * Separated from {@see SendsSmsInterface} so send-only providers can opt out of
 * status tracking without violating the composite {@see SmsProviderInterface}.
 */
interface TracksSmsStatusInterface
{
    /**
     * Look up the current status for a previously sent message.
     *
     * @param string $messageId Identifier previously returned by
     *                          {@see SendsSmsInterface::send()} in
     *                          {@see \SmsGateway\DTO\SendResult::$messageId}.
     *
     * @throws MessageNotFoundException When the provider does not recognize the id.
     * @throws ProviderException        For any other remote/transport failure.
     */
    public function getStatus(string $messageId): StatusResult;
}
