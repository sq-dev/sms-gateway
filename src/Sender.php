<?php

declare(strict_types=1);

namespace SmsGateway;

use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\DTO\SendResult;
use SmsGateway\DTO\SmsMessage;

/**
 * Convenience entry-point for dispatching SMS messages through any provider.
 *
 * The `Sender` is a thin facade around a {@see SendsSmsInterface} that lets
 * consumer code avoid constructing {@see SmsMessage} objects for ad-hoc sends.
 * Everything this class does can also be done by calling the provider directly,
 * but `Sender::send()` reads noticeably better in call sites:
 *
 * ```php
 * use SmsGateway\Sender;
 * use SmsGateway\Providers\Payom\PayomSmsProvider;
 *
 * $sender = new Sender(new PayomSmsProvider(
 *     token: 'jwt-token',
 *     defaultSenderName: 'payom.tj',
 * ));
 *
 * $result = $sender->send('+992937123456', 'Your code is 1234.');
 * ```
 *
 * When you need access to advanced message metadata or provider-specific
 * contracts (e.g. status tracking on a provider that supports it), use
 * {@see self::sendMessage()} or reach for the underlying provider via
 * {@see self::provider()}.
 */
final class Sender
{
    public function __construct(
        private readonly SendsSmsInterface $provider,
    ) {
    }

    /**
     * Send a message using positional arguments.
     *
     * Constructs an {@see SmsMessage} internally, so the same DTO validation
     * applies - an {@see \SmsGateway\Exception\InvalidMessageException} is
     * thrown for empty recipients, empty text, or blank senders.
     *
     * @param string               $to       Recipient address.
     * @param string               $text     Message body.
     * @param string|null          $from     Optional per-message sender id; providers may fall
     *                                       back to a default configured on their side.
     * @param array<string, mixed> $metadata Provider-agnostic hints passed through to the adapter.
     *
     * @throws \SmsGateway\Exception\SmsGatewayException
     */
    public function send(
        string $to,
        string $text,
        ?string $from = null,
        array $metadata = [],
    ): SendResult {
        return $this->provider->send(new SmsMessage(
            to: $to,
            text: $text,
            from: $from,
            metadata: $metadata,
        ));
    }

    /**
     * Send a pre-built {@see SmsMessage}.
     *
     * Use this variant when the message DTO is built somewhere else in the
     * application (e.g. in a domain service) or when metadata needs to be
     * assembled incrementally via {@see SmsMessage::withMetadata()}.
     *
     * @throws \SmsGateway\Exception\SmsGatewayException
     */
    public function sendMessage(SmsMessage $message): SendResult
    {
        return $this->provider->send($message);
    }

    /**
     * Return the underlying provider.
     *
     * Exposed so callers that need capabilities outside the send contract
     * (e.g. status tracking through
     * {@see \SmsGateway\Contracts\TracksSmsStatusInterface}) can type-check
     * against the richer interface without going around the facade:
     *
     * ```php
     * $provider = $sender->provider();
     * if ($provider instanceof TracksSmsStatusInterface) {
     *     $provider->getStatus($result->messageId);
     * }
     * ```
     */
    public function provider(): SendsSmsInterface
    {
        return $this->provider;
    }
}
