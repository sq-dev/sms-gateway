<?php

declare(strict_types=1);

namespace SmsGateway\Providers\Payom;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\DTO\SendResult;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\InvalidMessageException;
use SmsGateway\Exception\ProviderException;

/**
 * Payom.tj SMS gateway adapter.
 *
 * Implements only {@see SendsSmsInterface} because the public Payom API
 * (v1.0, 12.07.2025) documents a single outbound endpoint `POST /api/message`
 * and does not expose a status-lookup endpoint. If Payom later publishes one,
 * this class can gain {@see \SmsGateway\Contracts\TracksSmsStatusInterface}
 * without breaking existing consumers.
 *
 * ## Usage
 *
 * The primary constructor takes only business configuration:
 *
 * ```php
 * $payom = new PayomSmsProvider(
 *     token: 'jwt-token',
 *     defaultSenderName: 'payom.tj',
 * );
 * ```
 *
 * The HTTP stack is auto-discovered via `php-http/discovery`, which finds any
 * installed PSR-18 client (Guzzle, Symfony HttpClient, php-http/curl-client,
 * ...) and PSR-17 factories (nyholm/psr7, guzzle/psr7, ...).
 *
 * For tests and DI containers, explicit PSR-18/17 objects can be injected:
 *
 * ```php
 * $payom = new PayomSmsProvider(
 *     token: 'jwt-token',
 *     defaultSenderName: 'payom.tj',
 *     httpClient: $psr18Client,
 *     requestFactory: $psr17Factory,
 *     streamFactory: $psr17Factory,
 * );
 * ```
 *
 * @link https://gateway.payom.tj/
 */
final class PayomSmsProvider implements SendsSmsInterface
{
    public const PROVIDER_NAME = 'payom';
    public const DEFAULT_BASE_URI = 'https://gateway.payom.tj';
    private const SEND_PATH = '/api/message';
    private const MESSAGE_TYPE = 'SMS';

    private readonly string $baseUri;
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param string                       $token             JWT bearer token issued by Payom.tj
     *                                                        under "Настройки API". Must be
     *                                                        non-empty.
     * @param string|null                  $defaultSenderName Pre-registered sender id used
     *                                                        whenever {@see SmsMessage::$from} is
     *                                                        null.
     * @param ClientInterface|null         $httpClient        Optional PSR-18 HTTP client. When
     *                                                        null, one is auto-discovered via
     *                                                        `php-http/discovery`.
     * @param RequestFactoryInterface|null $requestFactory    Optional PSR-17 request factory.
     *                                                        Auto-discovered when null.
     * @param StreamFactoryInterface|null  $streamFactory     Optional PSR-17 stream factory.
     *                                                        Auto-discovered when null.
     * @param string                       $baseUri           Base URI of the Payom gateway. The
     *                                                        default points at production.
     */
    public function __construct(
        private readonly string $token,
        private readonly ?string $defaultSenderName = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        if (trim($this->token) === '') {
            throw new InvalidArgumentException('Payom API token must not be empty.');
        }

        if ($this->defaultSenderName !== null && trim($this->defaultSenderName) === '') {
            throw new InvalidArgumentException(
                'Payom default sender name must be null or a non-empty string.',
            );
        }

        if (trim($baseUri) === '') {
            throw new InvalidArgumentException('Payom base URI must not be empty.');
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
        $senderName = $message->from ?? $this->defaultSenderName;

        if ($senderName === null) {
            throw new InvalidMessageException(
                'Payom requires a sender name. Pass SmsMessage::$from or configure a '
                . 'default sender name on the provider.',
            );
        }

        $payload = [
            'telephone' => $message->to,
            'text' => $message->text,
            'senderName' => $senderName,
            'type' => self::MESSAGE_TYPE,
        ];

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderException(
                'Could not encode the Payom request body: ' . $e->getMessage(),
                self::PROVIDER_NAME,
                null,
                $e,
            );
        }

        $request = $this->requestFactory
            ->createRequest('POST', $this->baseUri . self::SEND_PATH)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ProviderException(
                'Payom request failed before a response was received: ' . $e->getMessage(),
                self::PROVIDER_NAME,
                null,
                $e,
            );
        }

        return $this->buildResult($response);
    }

    private function buildResult(ResponseInterface $response): SendResult
    {
        $statusCode = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $this->decodeJson($raw);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ProviderException(
                sprintf(
                    'Payom API rejected the request (HTTP %d): %s',
                    $statusCode,
                    $this->extractErrorMessage($decoded, $raw, $statusCode),
                ),
                self::PROVIDER_NAME,
                (string) $statusCode,
            );
        }

        if (!isset($decoded['id']) || !is_string($decoded['id']) || $decoded['id'] === '') {
            throw new ProviderException(
                'Payom response is missing a valid "id" field.',
                self::PROVIDER_NAME,
                (string) $statusCode,
            );
        }

        $nativeStatus = $decoded['deliveryStatus'] ?? null;

        return new SendResult(
            messageId: $decoded['id'],
            status: is_string($nativeStatus)
                ? $this->mapStatus($nativeStatus)
                : MessageStatus::Unknown,
            providerName: self::PROVIDER_NAME,
            raw: $decoded,
        );
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractErrorMessage(array $decoded, string $raw, int $statusCode): string
    {
        foreach (['message', 'error', 'title', 'detail'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                return $decoded[$key];
            }
        }

        if ($raw !== '') {
            return mb_strimwidth($raw, 0, 200, '…');
        }

        return sprintf('HTTP %d', $statusCode);
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

    /**
     * Translate Payom's `deliveryStatus` value into the normalized enum.
     *
     * Only `ACCEPTED` is documented in the public API example, so every other
     * mapping is a best-effort guess based on Payom's error taxonomy and
     * industry conventions. Unknown values fall back to
     * {@see MessageStatus::Unknown} so the library never silently mislabels a
     * state.
     */
    private function mapStatus(string $native): MessageStatus
    {
        return match (strtoupper(trim($native))) {
            'ACCEPTED', 'QUEUED', 'PENDING', 'NEW' => MessageStatus::Queued,
            'SENT', 'IN_PROGRESS' => MessageStatus::Sent,
            'DELIVERED' => MessageStatus::Delivered,
            'REJECTED' => MessageStatus::Rejected,
            'UNDELIVERED', 'NOT_DELIVERED' => MessageStatus::Undelivered,
            'FAILED', 'ERROR' => MessageStatus::Failed,
            'EXPIRED' => MessageStatus::Expired,
            default => MessageStatus::Unknown,
        };
    }
}
