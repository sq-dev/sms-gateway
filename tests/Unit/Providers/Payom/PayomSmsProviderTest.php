<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit\Providers\Payom;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\Contracts\SmsProviderInterface;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\InvalidMessageException;
use SmsGateway\Exception\ProviderException;
use SmsGateway\Providers\Payom\PayomSmsProvider;
use SmsGateway\Tests\Fixtures\MockHttpClient;
use SmsGateway\Tests\Fixtures\TransportException;

#[CoversClass(PayomSmsProvider::class)]
final class PayomSmsProviderTest extends TestCase
{
    private const SAMPLE_SUCCESS_BODY = <<<'JSON'
    {
      "id": "11111111-2222-3333-4444-555555555555",
      "telephone": "+992937123456",
      "text": "test",
      "createAt": "2025-07-12T14:01:32+03:00",
      "sentAt": null,
      "updatedAt": null,
      "type": "SMS",
      "deliveryStatus": "ACCEPTED",
      "configuration": {
        "senderName": "payom.tj",
        "operator": "TCELL",
        "quantityByLength": 1
      },
      "deliveryStatusLabel": "Принят"
    }
    JSON;

    public function test_implements_only_the_send_contract(): void
    {
        $provider = $this->makeProvider();

        self::assertInstanceOf(SendsSmsInterface::class, $provider);
        self::assertNotInstanceOf(
            SmsProviderInterface::class,
            $provider,
            'Payom does not document status tracking, so it must not claim the composite contract.',
        );
    }

    public function test_get_name_is_stable(): void
    {
        self::assertSame('payom', $this->makeProvider()->getName());
        self::assertSame(PayomSmsProvider::PROVIDER_NAME, $this->makeProvider()->getName());
    }

    public function test_can_be_constructed_with_only_business_config(): void
    {
        $provider = new PayomSmsProvider(
            token: 'jwt-token',
            defaultSenderName: 'payom.tj',
        );

        self::assertSame(PayomSmsProvider::PROVIDER_NAME, $provider->getName());
    }

    public function test_rejects_empty_token_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PayomSmsProvider(
            token: '   ',
            httpClient: new MockHttpClient(),
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
        );
    }

    public function test_rejects_blank_default_sender_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PayomSmsProvider(
            token: 'jwt',
            defaultSenderName: '   ',
            httpClient: new MockHttpClient(),
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
        );
    }

    public function test_rejects_empty_base_uri(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PayomSmsProvider(
            token: 'jwt',
            httpClient: new MockHttpClient(),
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            baseUri: '   ',
        );
    }

    public function test_send_builds_expected_request(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, ['Content-Type' => 'application/json'], self::SAMPLE_SUCCESS_BODY));

        $provider = $this->makeProvider(http: $http, token: 'jwt-token', defaultSenderName: 'payom.tj');
        $provider->send(new SmsMessage(to: '+992937123456', text: 'test'));

        $request = $http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://gateway.payom.tj/api/message', (string) $request->getUri());
        self::assertSame('Bearer jwt-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        self::assertSame(
            [
                'telephone' => '+992937123456',
                'text' => 'test',
                'senderName' => 'payom.tj',
                'type' => 'SMS',
            ],
            json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_send_prefers_message_sender_over_default(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SUCCESS_BODY));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'default-sender');
        $provider->send(new SmsMessage('+992900000000', 'hello', from: 'message-sender'));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('message-sender', $body['senderName']);
    }

    public function test_send_strips_trailing_slash_from_base_uri(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SUCCESS_BODY));

        $provider = $this->makeProvider(
            http: $http,
            defaultSenderName: 'payom.tj',
            baseUri: 'https://gateway.payom.tj/',
        );
        $provider->send(new SmsMessage('+992900000000', 'hello'));

        self::assertSame(
            'https://gateway.payom.tj/api/message',
            (string) $http->lastRequest()->getUri(),
        );
    }

    public function test_send_returns_normalized_result_on_201(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SUCCESS_BODY));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'payom.tj')
            ->send(new SmsMessage('+992937123456', 'test'));

        self::assertSame('11111111-2222-3333-4444-555555555555', $result->messageId);
        self::assertSame(MessageStatus::Queued, $result->status);
        self::assertSame('payom', $result->providerName);
        self::assertSame('TCELL', $result->raw['configuration']['operator']);
    }

    public function test_send_without_sender_throws_invalid_message_exception(): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http);

        $this->expectException(InvalidMessageException::class);

        try {
            $provider->send(new SmsMessage('+992937123456', 'test'));
        } finally {
            self::assertSame([], $http->requests, 'No request should have been dispatched.');
        }
    }

    public function test_send_translates_transport_failure_into_provider_exception(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new TransportException(new Request('POST', 'https://gateway.payom.tj/api/message')));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'payom.tj');

        try {
            $provider->send(new SmsMessage('+992937123456', 'test'));
            self::fail('Expected ProviderException to be thrown.');
        } catch (ProviderException $exception) {
            self::assertSame('payom', $exception->getProviderName());
            self::assertNull($exception->getProviderCode());
            self::assertInstanceOf(TransportException::class, $exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function errorResponses(): iterable
    {
        yield '401 unauthorized' => [401, '{"message": "Unauthorized"}'];
        yield '403 forbidden'    => [403, '{"error": "Access Denied"}'];
        yield '422 validation'   => [422, '{"message": "Invalid phone"}'];
        yield '500 server error' => [500, '{"title": "Internal Server Error"}'];
        yield '502 non-json'     => [502, '<html>Bad Gateway</html>'];
    }

    #[DataProvider('errorResponses')]
    public function test_send_translates_http_error_into_provider_exception(
        int $statusCode,
        string $body,
    ): void {
        $http = new MockHttpClient();
        $http->enqueue(new Response($statusCode, [], $body));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'payom.tj');

        try {
            $provider->send(new SmsMessage('+992937123456', 'test'));
            self::fail('Expected ProviderException to be thrown.');
        } catch (ProviderException $exception) {
            self::assertSame('payom', $exception->getProviderName());
            self::assertSame((string) $statusCode, $exception->getProviderCode());
            self::assertStringContainsString((string) $statusCode, $exception->getMessage());
        }
    }

    public function test_send_fails_when_response_has_no_id(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], '{"deliveryStatus": "ACCEPTED"}'));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'payom.tj');

        $this->expectException(ProviderException::class);

        $provider->send(new SmsMessage('+992937123456', 'test'));
    }

    public function test_send_defaults_to_unknown_status_when_not_reported(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], '{"id": "abc"}'));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'payom.tj')
            ->send(new SmsMessage('+992937123456', 'test'));

        self::assertSame(MessageStatus::Unknown, $result->status);
    }

    /**
     * @return iterable<string, array{0: string, 1: MessageStatus}>
     */
    public static function statusMappings(): iterable
    {
        yield 'ACCEPTED -> Queued'       => ['ACCEPTED', MessageStatus::Queued];
        yield 'accepted lower -> Queued' => ['accepted', MessageStatus::Queued];
        yield 'PENDING -> Queued'        => ['PENDING', MessageStatus::Queued];
        yield 'SENT -> Sent'             => ['SENT', MessageStatus::Sent];
        yield 'DELIVERED -> Delivered'   => ['DELIVERED', MessageStatus::Delivered];
        yield 'REJECTED -> Rejected'     => ['REJECTED', MessageStatus::Rejected];
        yield 'UNDELIVERED -> Undelivered' => ['UNDELIVERED', MessageStatus::Undelivered];
        yield 'FAILED -> Failed'         => ['FAILED', MessageStatus::Failed];
        yield 'EXPIRED -> Expired'       => ['EXPIRED', MessageStatus::Expired];
        yield 'foobar -> Unknown'        => ['SOMETHING_WEIRD', MessageStatus::Unknown];
    }

    #[DataProvider('statusMappings')]
    public function test_maps_delivery_status_values(string $native, MessageStatus $expected): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(
            201,
            [],
            sprintf('{"id": "abc", "deliveryStatus": %s}', json_encode($native, JSON_THROW_ON_ERROR)),
        ));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'payom.tj')
            ->send(new SmsMessage('+992937123456', 'test'));

        self::assertSame($expected, $result->status);
    }

    private function makeProvider(
        ?MockHttpClient $http = null,
        string $token = 'jwt',
        ?string $defaultSenderName = null,
        string $baseUri = PayomSmsProvider::DEFAULT_BASE_URI,
    ): PayomSmsProvider {
        $factory = new Psr17Factory();

        return new PayomSmsProvider(
            httpClient: $http ?? new MockHttpClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            token: $token,
            defaultSenderName: $defaultSenderName,
            baseUri: $baseUri,
        );
    }
}
