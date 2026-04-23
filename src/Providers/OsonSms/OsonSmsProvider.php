<?php

declare(strict_types=1);

namespace SmsGateway\Providers\OsonSms;

use DateTimeImmutable;
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
 * OsonSMS gateway adapter (osonsms.com).
 *
 * Implements the composite {@see SmsProviderInterface} because the OsonSMS
 * public protocol (v2.0.2, 08.02.2026) exposes both `/sendsms_v1.php` and
 * `/query_sms.php` for send and status tracking.
 *
 * ## Usage
 *
 * ```php
 * $oson = new OsonSmsProvider(
 *     token: 'bearer-token',
 *     login: 'account-login',
 *     defaultSenderName: 'MYBRAND',
 * );
 *
 * $result = $oson->send(new SmsMessage('+992900000000', 'Hello'));
 * $oson->getStatus($result->messageId); // round-trips via the composite id
 * ```
 *
 * ## Composite message id
 *
 * OsonSMS's status lookup requires both `txn_id` (client-side idempotency key)
 * and `msg_id` (server-side id). To fit those into our single-string
 * {@see SendResult::$messageId}, this adapter encodes them as
 * `"{txn_id}|{msg_id}"`. Consumers should treat the value as opaque and not
 * depend on the format; {@see self::getStatus()} parses it back.
 *
 * @link https://osonsms.com/docs/sms-api-documentation.pdf
 */
final class OsonSmsProvider implements SmsProviderInterface
{
    public const PROVIDER_NAME = 'osonsms';
    public const DEFAULT_BASE_URI = 'https://api.osonsms.com';

    /** Metadata key consumers can set to supply their own txn_id instead of auto-generating one. */
    public const METADATA_TXN_ID = 'txn_id';

    /** Metadata key for the optional messenger channel (e.g. "telegram"). */
    public const METADATA_CHANNEL = 'channel';

    /** Metadata key for the `is_confidential` flag. */
    public const METADATA_CONFIDENTIAL = 'is_confidential';

    private const SEND_PATH = '/sendsms_v1.php';
    private const STATUS_PATH = '/query_sms.php';
    private const MESSAGE_ID_SEPARATOR = '|';

    private readonly string $baseUri;
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param string                       $token             Bearer token issued by OsonSMS.
     * @param string                       $login             Account login issued by OsonSMS.
     * @param string|null                  $defaultSenderName Sender id used whenever
     *                                                        {@see SmsMessage::$from} is null.
     * @param ClientInterface|null         $httpClient        Optional PSR-18 HTTP client.
     *                                                        Auto-discovered when null.
     * @param RequestFactoryInterface|null $requestFactory    Optional PSR-17 request factory.
     *                                                        Auto-discovered when null.
     * @param StreamFactoryInterface|null  $streamFactory     Optional PSR-17 stream factory.
     *                                                        Auto-discovered when null.
     * @param string                       $baseUri           Base URI of the OsonSMS send/status
     *                                                        gateway. Defaults to production.
     */
    public function __construct(
        private readonly string $token,
        private readonly string $login,
        private readonly ?string $defaultSenderName = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        if (trim($this->token) === '') {
            throw new InvalidArgumentException('OsonSMS API token must not be empty.');
        }

        if (trim($this->login) === '') {
            throw new InvalidArgumentException('OsonSMS login must not be empty.');
        }

        if ($this->defaultSenderName !== null && trim($this->defaultSenderName) === '') {
            throw new InvalidArgumentException(
                'OsonSMS default sender name must be null or a non-empty string.',
            );
        }

        if (trim($baseUri) === '') {
            throw new InvalidArgumentException('OsonSMS base URI must not be empty.');
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
                'OsonSMS requires a sender name. Pass SmsMessage::$from or configure a '
                . 'default sender name on the provider.',
            );
        }

        $txnId = $this->resolveTxnId($message->metadata);
        $query = [
            'from' => $sender,
            'phone_number' => $this->normalizePhone($message->to),
            'msg' => $message->text,
            'login' => $this->login,
            'txn_id' => $txnId,
        ];

        if (isset($message->metadata[self::METADATA_CHANNEL])
            && is_string($message->metadata[self::METADATA_CHANNEL])
            && $message->metadata[self::METADATA_CHANNEL] !== ''
        ) {
            $query['channel'] = $message->metadata[self::METADATA_CHANNEL];
        }

        if (array_key_exists(self::METADATA_CONFIDENTIAL, $message->metadata)) {
            $query['is_confidential'] = $message->metadata[self::METADATA_CONFIDENTIAL]
                ? 'true'
                : 'false';
        }

        $response = $this->dispatch($this->buildRequest(self::SEND_PATH, $query));
        $statusCode = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $this->decodeJson($raw);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw $this->buildErrorException('send', $statusCode, $decoded, $raw);
        }

        $msgId = $decoded['msg_id'] ?? null;
        if (!is_scalar($msgId) || (string) $msgId === '') {
            throw new ProviderException(
                'OsonSMS send response is missing a valid "msg_id" field.',
                self::PROVIDER_NAME,
                (string) $statusCode,
            );
        }

        return new SendResult(
            messageId: $this->encodeMessageId($txnId, (string) $msgId),
            status: MessageStatus::Queued,
            providerName: self::PROVIDER_NAME,
            raw: array_merge($decoded, ['txn_id' => $txnId]),
        );
    }

    public function getStatus(string $messageId): StatusResult
    {
        [$txnId, $msgId] = $this->decodeMessageId($messageId);

        $response = $this->dispatch($this->buildRequest(self::STATUS_PATH, [
            'login' => $this->login,
            'txn_id' => $txnId,
            'msg_id' => $msgId,
        ]));

        $statusCode = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $this->decodeJson($raw);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw $this->buildErrorException('status', $statusCode, $decoded, $raw);
        }

        $nativeStatus = $decoded['status'] ?? null;
        if (!is_string($nativeStatus) || $nativeStatus === '') {
            throw new ProviderException(
                'OsonSMS status response is missing a valid "status" field.',
                self::PROVIDER_NAME,
                (string) $statusCode,
            );
        }

        return new StatusResult(
            messageId: $messageId,
            status: $this->mapStatus($nativeStatus),
            providerName: self::PROVIDER_NAME,
            updatedAt: $this->parseTimestamp($decoded['timestamp'] ?? null),
            raw: $decoded,
        );
    }

    /**
     * @param array<string, scalar> $query
     */
    private function buildRequest(string $path, array $query): RequestInterface
    {
        $uri = $this->baseUri . $path . '?' . http_build_query($query);

        return $this->requestFactory
            ->createRequest('GET', $uri)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->token);
    }

    private function dispatch(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ProviderException(
                'OsonSMS request failed before a response was received: ' . $e->getMessage(),
                self::PROVIDER_NAME,
                null,
                $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveTxnId(array $metadata): string
    {
        $provided = $metadata[self::METADATA_TXN_ID] ?? null;

        if ($provided === null) {
            return bin2hex(random_bytes(16));
        }

        if (!is_string($provided) || $provided === '') {
            throw new InvalidMessageException(
                'OsonSMS metadata["txn_id"] must be a non-empty string when provided.',
            );
        }

        if (str_contains($provided, self::MESSAGE_ID_SEPARATOR)) {
            throw new InvalidMessageException(sprintf(
                'OsonSMS metadata["txn_id"] must not contain "%s" (reserved as internal separator '
                . 'in the composite messageId).',
                self::MESSAGE_ID_SEPARATOR,
            ));
        }

        return $provided;
    }

    private function encodeMessageId(string $txnId, string $msgId): string
    {
        return $txnId . self::MESSAGE_ID_SEPARATOR . $msgId;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decodeMessageId(string $messageId): array
    {
        if (!str_contains($messageId, self::MESSAGE_ID_SEPARATOR)) {
            throw new InvalidMessageException(sprintf(
                'Invalid OsonSMS messageId "%s": expected "txn_id%smsg_id" format produced by '
                . 'send().',
                $messageId,
                self::MESSAGE_ID_SEPARATOR,
            ));
        }

        [$txnId, $msgId] = explode(self::MESSAGE_ID_SEPARATOR, $messageId, 2);

        if ($txnId === '' || $msgId === '') {
            throw new InvalidMessageException(
                'Invalid OsonSMS messageId: both txn_id and msg_id parts must be non-empty.',
            );
        }

        return [$txnId, $msgId];
    }

    /**
     * Strip the international `+` prefix plus any separators that are valid in
     * human-readable phone numbers but not accepted by the OsonSMS API.
     *
     * OsonSMS expects the raw `992XXXXXXXXX` form; malformed inputs are
     * intentionally passed through unchanged so the server can reject them
     * with the most precise error code (107 / 422).
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
        $providerCode = (string) $statusCode;
        $reason = null;

        $errorBlock = $decoded['error'] ?? null;
        if (is_array($errorBlock)) {
            $code = $errorBlock['code'] ?? null;
            if (is_scalar($code) && (string) $code !== '') {
                $providerCode = (string) $code;
            }

            $msg = $errorBlock['msg'] ?? null;
            if (is_string($msg) && $msg !== '') {
                $reason = $msg;
            }
        }

        if ($reason === null) {
            $reason = $raw !== ''
                ? mb_strimwidth($raw, 0, 200, '…')
                : sprintf('HTTP %d', $statusCode);
        }

        return new ProviderException(
            sprintf(
                'OsonSMS %s request failed (HTTP %d, code %s): %s',
                $operation,
                $statusCode,
                $providerCode,
                $reason,
            ),
            self::PROVIDER_NAME,
            $providerCode,
        );
    }

    private function mapStatus(string $native): MessageStatus
    {
        return match (strtoupper(trim($native))) {
            'ENROUTE' => MessageStatus::Queued,
            'ACCEPTED' => MessageStatus::Sent,
            'DELIVERED' => MessageStatus::Delivered,
            'EXPIRED' => MessageStatus::Expired,
            'UNDELIVERABLE' => MessageStatus::Undelivered,
            'DELETED', 'REJECTED' => MessageStatus::Rejected,
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
