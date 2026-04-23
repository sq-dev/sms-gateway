<?php

declare(strict_types=1);

namespace SmsGateway\Providers\SmsGate;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SmsGateway\Contracts\SmsProviderInterface;
use SmsGateway\DTO\SendResult;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\DTO\StatusResult;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\InvalidMessageException;
use SmsGateway\Exception\ProviderException;
use Throwable;

/**
 * SMSGate (Alif Tech) gateway adapter.
 *
 * Implements the composite {@see SmsProviderInterface} because the SMSGate
 * HTTP API exposes both `POST /api/v1/sms` and `GET /api/v1/sms/{id}` for
 * sending and status tracking.
 *
 * ## Usage
 *
 * ```php
 * $smsgate = new SmsGateProvider(
 *     apiKey: 'your-api-key',
 *     defaultSenderName: 'AlifBank',
 * );
 *
 * $result = $smsgate->send(new SmsMessage('+992900900900', 'Hello'));
 * $smsgate->getStatus($result->messageId);
 * ```
 *
 * ## Authentication
 *
 * SMSGate uses the `X-Api-Key` header instead of the more common
 * `Authorization: Bearer ...`. The adapter sets it on every request.
 *
 * ## Optional metadata keys
 *
 * Use the `METADATA_*` constants (or the matching string keys) on
 * {@see SmsMessage::$metadata} to forward optional SMSGate fields:
 *
 * - `priority`         => `SmsPriority` enum or integer 0/1/2.
 * - `sms_type`         => `SmsType` enum or integer 1/2/3 (overrides constructor default).
 * - `scheduled_at`     => `DateTimeInterface` or ISO-8601 string (UTC).
 * - `expires_in`       => integer seconds, 0 = never expires.
 * - `label`            => arbitrary string for grouping in reports.
 * - `client_message_id`=> idempotency-friendly external id.
 *
 * @link https://docs.smsgate.tj/api/sms-api.html
 */
final class SmsGateProvider implements SmsProviderInterface
{
    public const PROVIDER_NAME = 'smsgate';

    /** Primary production endpoint, as documented by SMSGate. */
    public const DEFAULT_BASE_URI = 'https://sms2.aliftech.net';

    /** Backup endpoint documented by SMSGate as a fallback host. */
    public const FALLBACK_BASE_URI = 'https://smsgate.tj';

    public const METADATA_PRIORITY = 'priority';
    public const METADATA_SMS_TYPE = 'sms_type';
    public const METADATA_SCHEDULED_AT = 'scheduled_at';
    public const METADATA_EXPIRES_IN = 'expires_in';
    public const METADATA_LABEL = 'label';
    public const METADATA_CLIENT_MESSAGE_ID = 'client_message_id';

    private const SEND_PATH = '/api/v1/sms';
    private const STATUS_PATH = '/api/v1/sms/';

    private readonly string $baseUri;
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param string                       $apiKey            X-Api-Key value issued by SMSGate.
     * @param string|null                  $defaultSenderName Pre-registered sender id used
     *                                                        whenever {@see SmsMessage::$from}
     *                                                        is null.
     * @param SmsType                      $defaultSmsType    Default `SmsType` for messages that
     *                                                        do not override it via metadata.
     * @param ClientInterface|null         $httpClient        Optional PSR-18 HTTP client.
     *                                                        Auto-discovered when null.
     * @param RequestFactoryInterface|null $requestFactory    Optional PSR-17 request factory.
     *                                                        Auto-discovered when null.
     * @param StreamFactoryInterface|null  $streamFactory     Optional PSR-17 stream factory.
     *                                                        Auto-discovered when null.
     * @param string                       $baseUri           Base URI of the SMSGate API.
     *                                                        Defaults to the documented primary
     *                                                        endpoint; pass
     *                                                        {@see self::FALLBACK_BASE_URI}
     *                                                        for the documented backup host.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $defaultSenderName = null,
        private readonly SmsType $defaultSmsType = SmsType::Common,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        if (trim($this->apiKey) === '') {
            throw new InvalidArgumentException('SMSGate API key must not be empty.');
        }

        if ($this->defaultSenderName !== null && trim($this->defaultSenderName) === '') {
            throw new InvalidArgumentException(
                'SMSGate default sender name must be null or a non-empty string.',
            );
        }

        if (trim($baseUri) === '') {
            throw new InvalidArgumentException('SMSGate base URI must not be empty.');
        }

        $this->baseUri = rtrim($baseUri, '/');
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function send(SmsMessage $message): SendResult
    {
        $sender = $message->from ?? $this->defaultSenderName;

        if ($sender === null) {
            throw new InvalidMessageException(
                'SMSGate requires a sender address. Pass SmsMessage::$from or configure '
                . 'a default sender name on the provider.',
            );
        }

        $payload = [
            'PhoneNumber' => $this->normalizePhone($message->to),
            'Text' => $message->text,
            'SenderAddress' => $sender,
            'SmsType' => $this->resolveSmsType($message->metadata)->value,
        ];

        $this->applyOptionalFields($payload, $message->metadata);

        $request = $this->buildRequest('POST', self::SEND_PATH);

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new ProviderException(
                'Could not encode the SMSGate request body: ' . $e->getMessage(),
                self::PROVIDER_NAME,
                null,
                $e,
            );
        }

        $request = $request
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->dispatch($request);
        $statusCode = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $this->decodeJson($raw);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw $this->buildErrorException('send', $statusCode, $decoded, $raw);
        }

        if (($decoded['MessageError'] ?? null) === true) {
            $reason = is_string($decoded['MessageResult'] ?? null) && $decoded['MessageResult'] !== ''
                ? $decoded['MessageResult']
                : 'MessageError flag set without a reason.';

            throw new ProviderException(
                sprintf('SMSGate rejected the message: %s', $reason),
                self::PROVIDER_NAME,
                is_string($decoded['MessageResult'] ?? null) ? $decoded['MessageResult'] : null,
            );
        }

        $messageId = $decoded['MessageId'] ?? null;
        if (!is_scalar($messageId) || (string) $messageId === '') {
            throw new ProviderException(
                'SMSGate send response is missing a valid "MessageId" field.',
                self::PROVIDER_NAME,
                (string) $statusCode,
            );
        }

        return new SendResult(
            messageId: (string) $messageId,
            status: MessageStatus::Queued,
            providerName: self::PROVIDER_NAME,
            raw: $decoded,
        );
    }

    public function getStatus(string $messageId): StatusResult
    {
        if (trim($messageId) === '') {
            throw new InvalidMessageException('SMSGate messageId must not be empty.');
        }

        $request = $this->buildRequest('GET', self::STATUS_PATH . rawurlencode($messageId));

        $response = $this->dispatch($request);
        $statusCode = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $this->decodeJson($raw);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw $this->buildErrorException('status', $statusCode, $decoded, $raw);
        }

        $commandStatus = $decoded['CommandStatus'] ?? null;
        if (is_string($commandStatus) && strtoupper($commandStatus) !== 'OK') {
            throw new ProviderException(
                sprintf('SMSGate status request failed: CommandStatus=%s', $commandStatus),
                self::PROVIDER_NAME,
                $commandStatus,
            );
        }

        $messageState = $decoded['MessageState'] ?? null;
        if ($messageState === null || $messageState === '') {
            throw new ProviderException(
                'SMSGate status response is missing a valid "MessageState" field.',
                self::PROVIDER_NAME,
                (string) $statusCode,
            );
        }

        return new StatusResult(
            messageId: $messageId,
            status: $this->mapStatus($messageState),
            providerName: self::PROVIDER_NAME,
            updatedAt: $this->parseTimestamp($decoded['DateDone'] ?? $decoded['DateSubmitted'] ?? null),
            raw: $decoded,
        );
    }

    private function buildRequest(string $method, string $path): RequestInterface
    {
        return $this->requestFactory
            ->createRequest($method, $this->baseUri . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Api-Key', $this->apiKey);
    }

    private function dispatch(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ProviderException(
                'SMSGate request failed before a response was received: ' . $e->getMessage(),
                self::PROVIDER_NAME,
                null,
                $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload  Mutated by reference.
     * @param array<string, mixed> $metadata
     */
    private function applyOptionalFields(array &$payload, array $metadata): void
    {
        if (array_key_exists(self::METADATA_PRIORITY, $metadata)) {
            $payload['Priority'] = $this->resolvePriority($metadata[self::METADATA_PRIORITY])->value;
        }

        if (array_key_exists(self::METADATA_SCHEDULED_AT, $metadata)) {
            $payload['ScheduledAt'] = $this->normalizeScheduledAt($metadata[self::METADATA_SCHEDULED_AT]);
        }

        if (array_key_exists(self::METADATA_EXPIRES_IN, $metadata)) {
            $expiresIn = $metadata[self::METADATA_EXPIRES_IN];
            if (!is_int($expiresIn) || $expiresIn < 0) {
                throw new InvalidMessageException(
                    'SMSGate metadata["expires_in"] must be a non-negative integer (seconds).',
                );
            }
            $payload['ExpiresIn'] = $expiresIn;
        }

        if (array_key_exists(self::METADATA_LABEL, $metadata)) {
            $label = $metadata[self::METADATA_LABEL];
            if (!is_string($label) || $label === '') {
                throw new InvalidMessageException(
                    'SMSGate metadata["label"] must be a non-empty string.',
                );
            }
            $payload['SmsLabel'] = $label;
        }

        if (array_key_exists(self::METADATA_CLIENT_MESSAGE_ID, $metadata)) {
            $clientMessageId = $metadata[self::METADATA_CLIENT_MESSAGE_ID];
            if (!is_string($clientMessageId) || $clientMessageId === '') {
                throw new InvalidMessageException(
                    'SMSGate metadata["client_message_id"] must be a non-empty string.',
                );
            }
            $payload['ClientMessageId'] = $clientMessageId;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveSmsType(array $metadata): SmsType
    {
        if (!array_key_exists(self::METADATA_SMS_TYPE, $metadata)) {
            return $this->defaultSmsType;
        }

        $value = $metadata[self::METADATA_SMS_TYPE];

        if ($value instanceof SmsType) {
            return $value;
        }

        if (is_int($value)) {
            $type = SmsType::tryFrom($value);
            if ($type !== null) {
                return $type;
            }
        }

        throw new InvalidMessageException(sprintf(
            'SMSGate metadata["sms_type"] must be a %s enum or one of the integers 1, 2, 3.',
            SmsType::class,
        ));
    }

    private function resolvePriority(mixed $value): SmsPriority
    {
        if ($value instanceof SmsPriority) {
            return $value;
        }

        if (is_int($value)) {
            $priority = SmsPriority::tryFrom($value);
            if ($priority !== null) {
                return $priority;
            }
        }

        throw new InvalidMessageException(sprintf(
            'SMSGate metadata["priority"] must be a %s enum or one of the integers 0, 1, 2.',
            SmsPriority::class,
        ));
    }

    private function normalizeScheduledAt(mixed $raw): string
    {
        if ($raw instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($raw)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        }

        if (is_string($raw) && trim($raw) !== '') {
            return $raw;
        }

        throw new InvalidMessageException(
            'SMSGate metadata["scheduled_at"] must be a DateTimeInterface or non-empty string.',
        );
    }

    /**
     * Strip the international `+` prefix and human-readable separators so the
     * payload matches the documented `992XXXXXXXXX` format.
     */
    private function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[\s\-()]+/', '', $phone);

        return ltrim($cleaned ?? $phone, '+');
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function buildErrorException(
        string $operation,
        int $statusCode,
        array $decoded,
        string $raw,
    ): ProviderException {
        $reason = null;

        foreach (['MessageResult', 'message', 'error', 'title', 'detail'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                $reason = $decoded[$key];
                break;
            }
        }

        if ($reason === null) {
            $reason = $raw !== ''
                ? mb_strimwidth($raw, 0, 200, '…')
                : sprintf('HTTP %d', $statusCode);
        }

        return new ProviderException(
            sprintf('SMSGate %s request failed (HTTP %d): %s', $operation, $statusCode, $reason),
            self::PROVIDER_NAME,
            (string) $statusCode,
        );
    }

    /**
     * Map SMSGate's `MessageState` (string or numeric code) into the normalized enum.
     *
     * The documented values are listed at:
     * @link https://docs.smsgate.tj/api/sms-api.html
     */
    private function mapStatus(mixed $native): MessageStatus
    {
        if (is_int($native) || (is_string($native) && ctype_digit(trim($native)))) {
            return match ((int) $native) {
                0 => MessageStatus::Unknown,
                1 => MessageStatus::Sent,
                2 => MessageStatus::Delivered,
                3 => MessageStatus::Expired,
                4 => MessageStatus::Rejected,
                5 => MessageStatus::Undelivered,
                6 => MessageStatus::Sent,
                7 => MessageStatus::Unknown,
                8 => MessageStatus::Rejected,
                default => MessageStatus::Unknown,
            };
        }

        if (!is_string($native)) {
            return MessageStatus::Unknown;
        }

        return match (strtoupper(trim($native))) {
            'NONE' => MessageStatus::Unknown,
            'ENROUTE' => MessageStatus::Sent,
            'DELIVERED' => MessageStatus::Delivered,
            'EXPIRED' => MessageStatus::Expired,
            'DELETED' => MessageStatus::Rejected,
            'UNDELIVERABLE' => MessageStatus::Undelivered,
            'ACCEPTED' => MessageStatus::Sent,
            'UNKNOWN' => MessageStatus::Unknown,
            'REJECTED' => MessageStatus::Rejected,
            default => MessageStatus::Unknown,
        };
    }

    private function parseTimestamp(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
