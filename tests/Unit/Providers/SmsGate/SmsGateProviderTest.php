<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit\Providers\SmsGate;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\Contracts\SmsProviderInterface;
use SmsGateway\Contracts\TracksSmsStatusInterface;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\InvalidMessageException;
use SmsGateway\Exception\ProviderException;
use SmsGateway\Providers\SmsGate\SmsGateProvider;
use SmsGateway\Providers\SmsGate\SmsPriority;
use SmsGateway\Providers\SmsGate\SmsType;
use SmsGateway\Tests\Fixtures\MockHttpClient;
use SmsGateway\Tests\Fixtures\TransportException;

#[CoversClass(SmsGateProvider::class)]
#[CoversClass(SmsType::class)]
#[CoversClass(SmsPriority::class)]
final class SmsGateProviderTest extends TestCase
{
    private const SAMPLE_SEND_SUCCESS = <<<'JSON'
    {
      "MessageId": "23861959",
      "MessageResult": "OK",
      "MessageError": false
    }
    JSON;

    private const SAMPLE_STATUS_SUCCESS = <<<'JSON'
    {
      "MessageId": "23861959",
      "CommandStatus": "OK",
      "MessageState": "Delivered",
      "DateSubmitted": "2026-02-17T10:20:30",
      "DateDone": "2026-02-17T10:22:00"
    }
    JSON;

    public function test_implements_full_composite_provider_contract(): void
    {
        $provider = $this->makeProvider();

        self::assertInstanceOf(SmsProviderInterface::class, $provider);
        self::assertInstanceOf(SendsSmsInterface::class, $provider);
        self::assertInstanceOf(TracksSmsStatusInterface::class, $provider);
    }

    public function test_get_name_is_stable(): void
    {
        self::assertSame('smsgate', $this->makeProvider()->getName());
        self::assertSame(SmsGateProvider::PROVIDER_NAME, $this->makeProvider()->getName());
    }

    public function test_can_be_constructed_with_only_business_config(): void
    {
        $provider = new SmsGateProvider(
            apiKey: 'test-key',
            defaultSenderName: 'AlifBank',
        );

        self::assertSame(SmsGateProvider::PROVIDER_NAME, $provider->getName());
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: ?string, 3: string}>
     */
    public static function invalidConstructorInputs(): iterable
    {
        yield 'empty api key'            => ['  ', null, null, 'API key'];
        yield 'blank default sender'     => ['key', '   ', null, 'sender'];
        yield 'empty base uri'           => ['key', null, '   ', 'base URI'];
    }

    #[DataProvider('invalidConstructorInputs')]
    public function test_rejects_invalid_constructor_input(
        string $apiKey,
        ?string $defaultSenderName,
        ?string $baseUri,
        string $expectedMessageFragment,
    ): void {
        $factory = new Psr17Factory();
        $http = new MockHttpClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessageFragment, '/') . '/i');

        new SmsGateProvider(
            apiKey: $apiKey,
            defaultSenderName: $defaultSenderName,
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUri: $baseUri ?? SmsGateProvider::DEFAULT_BASE_URI,
        );
    }

    public function test_send_builds_expected_post_request(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, apiKey: 'my-api-key');
        $provider->send(new SmsMessage(
            to: '+992900900900',
            text: 'Ваш код: 12345',
            from: 'AlifBank',
        ));

        $request = $http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://sms2.aliftech.net/api/v1/sms',
            (string) $request->getUri(),
        );
        self::assertSame('my-api-key', $request->getHeaderLine('X-Api-Key'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'PhoneNumber' => '992900900900',
            'Text' => 'Ваш код: 12345',
            'SenderAddress' => 'AlifBank',
            'SmsType' => 1,
        ], $body);
    }

    public function test_send_does_not_send_authorization_bearer_header(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage('+992900900900', 'hi'));

        self::assertSame('', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_send_prefers_message_sender_over_default(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'DEFAULT');
        $provider->send(new SmsMessage('+992900900900', 'hi', from: 'OVERRIDE'));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('OVERRIDE', $body['SenderAddress']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function phoneNormalizationCases(): iterable
    {
        yield 'plus prefix'              => ['+992900900900', '992900900900'];
        yield 'already normalized'       => ['992900900900', '992900900900'];
        yield 'spaces and hyphens'       => ['+992 90 090-09-00', '992900900900'];
        yield 'parentheses'              => ['+992 (90) 090-09-00', '992900900900'];
    }

    #[DataProvider('phoneNormalizationCases')]
    public function test_send_normalizes_phone_number(string $input, string $expected): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage($input, 'hi'));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($expected, $body['PhoneNumber']);
    }

    public function test_send_uses_constructor_default_sms_type(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(
            http: $http,
            defaultSenderName: 'AlifBank',
            defaultSmsType: SmsType::Otp,
        );
        $provider->send(new SmsMessage('+992900900900', 'hi'));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(SmsType::Otp->value, $body['SmsType']);
    }

    public function test_send_metadata_overrides_default_sms_type_via_enum(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage(
            '+992900900900',
            'OTP code: 1234',
            metadata: ['sms_type' => SmsType::Otp],
        ));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $body['SmsType']);
    }

    public function test_send_metadata_overrides_default_sms_type_via_integer(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage(
            '+992900900900',
            'hello',
            metadata: ['sms_type' => 3],
        ));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(3, $body['SmsType']);
    }

    public function test_send_rejects_invalid_sms_type_metadata(): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        $this->expectException(InvalidMessageException::class);

        try {
            $provider->send(new SmsMessage(
                '+992900900900',
                'hi',
                metadata: ['sms_type' => 99],
            ));
        } finally {
            self::assertSame([], $http->requests, 'No request should have been dispatched.');
        }
    }

    public function test_send_forwards_priority_via_enum(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage(
            '+992900900900',
            'OTP',
            metadata: ['priority' => SmsPriority::High],
        ));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $body['Priority']);
    }

    public function test_send_forwards_priority_via_integer(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage(
            '+992900900900',
            'hi',
            metadata: ['priority' => 0],
        ));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $body['Priority']);
    }

    public function test_send_rejects_invalid_priority_metadata(): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        $this->expectException(InvalidMessageException::class);

        try {
            $provider->send(new SmsMessage(
                '+992900900900',
                'hi',
                metadata: ['priority' => 'highest'],
            ));
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_send_does_not_include_priority_when_metadata_absent(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage('+992900900900', 'hi'));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('Priority', $body);
    }

    public function test_send_forwards_scheduled_at_from_datetime_immutable_in_utc(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $when = new DateTimeImmutable('2026-04-23 12:00:00', new DateTimeZone('Asia/Dushanbe'));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage(
            '+992900900900',
            'hi',
            metadata: ['scheduled_at' => $when],
        ));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2026-04-23T07:00:00Z', $body['ScheduledAt']);
    }

    public function test_send_forwards_scheduled_at_from_string_unchanged(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage(
            '+992900900900',
            'hi',
            metadata: ['scheduled_at' => '2026-02-17T10:20:30Z'],
        ));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2026-02-17T10:20:30Z', $body['ScheduledAt']);
    }

    public function test_send_rejects_invalid_scheduled_at_metadata(): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        $this->expectException(InvalidMessageException::class);

        $provider->send(new SmsMessage(
            '+992900900900',
            'hi',
            metadata: ['scheduled_at' => 12345],
        ));
    }

    public function test_send_forwards_optional_fields(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->send(new SmsMessage(
            '+992900900900',
            'hi',
            metadata: [
                'expires_in' => 60,
                'label' => 'campaign-2026-04',
                'client_message_id' => 'order-001',
            ],
        ));

        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(60, $body['ExpiresIn']);
        self::assertSame('campaign-2026-04', $body['SmsLabel']);
        self::assertSame('order-001', $body['ClientMessageId']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidOptionalFields(): iterable
    {
        yield 'expires_in negative'  => [['expires_in' => -1]];
        yield 'expires_in non-int'   => [['expires_in' => '60']];
        yield 'label empty'          => [['label' => '']];
        yield 'label non-string'     => [['label' => 123]];
        yield 'client id empty'      => [['client_message_id' => '']];
        yield 'client id non-string' => [['client_message_id' => 123]];
    }

    #[DataProvider('invalidOptionalFields')]
    public function test_send_rejects_invalid_optional_fields(array $metadata): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        $this->expectException(InvalidMessageException::class);

        try {
            $provider->send(new SmsMessage('+992900900900', 'hi', metadata: $metadata));
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_send_without_sender_throws_invalid_message_exception(): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http);

        $this->expectException(InvalidMessageException::class);

        try {
            $provider->send(new SmsMessage('+992900900900', 'hi'));
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_send_returns_normalized_result(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank')
            ->send(new SmsMessage('+992900900900', 'hi'));

        self::assertSame('23861959', $result->messageId);
        self::assertSame(MessageStatus::Queued, $result->status);
        self::assertSame('smsgate', $result->providerName);
        self::assertSame('OK', $result->raw['MessageResult']);
    }

    public function test_send_translates_message_error_flag_into_provider_exception(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], '{"MessageId":null,"MessageResult":"InvalidSenderAddress","MessageError":true}'));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        try {
            $provider->send(new SmsMessage('+992900900900', 'hi'));
            self::fail('Expected ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame('smsgate', $exception->getProviderName());
            self::assertSame('InvalidSenderAddress', $exception->getProviderCode());
            self::assertStringContainsString('InvalidSenderAddress', $exception->getMessage());
        }
    }

    public function test_send_fails_when_response_has_no_message_id(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], '{"MessageResult":"OK","MessageError":false}'));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/MessageId/');

        $provider->send(new SmsMessage('+992900900900', 'hi'));
    }

    public function test_send_translates_transport_failure_into_provider_exception(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new TransportException(new Request('POST', 'https://sms2.aliftech.net/api/v1/sms')));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        try {
            $provider->send(new SmsMessage('+992900900900', 'hi'));
            self::fail('Expected ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame('smsgate', $exception->getProviderName());
            self::assertNull($exception->getProviderCode());
            self::assertInstanceOf(TransportException::class, $exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function httpErrorResponses(): iterable
    {
        yield '400 bad request'  => [400, '{"MessageResult":"BadRequest"}'];
        yield '401 unauthorized' => [401, '{"message":"Invalid API key"}'];
        yield '404 not found'    => [404, '<html>not found</html>'];
        yield '500 server error' => [500, ''];
    }

    #[DataProvider('httpErrorResponses')]
    public function test_send_translates_http_errors_into_provider_exception(int $statusCode, string $body): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response($statusCode, [], $body));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        try {
            $provider->send(new SmsMessage('+992900900900', 'hi'));
            self::fail('Expected ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame('smsgate', $exception->getProviderName());
            self::assertSame((string) $statusCode, $exception->getProviderCode());
        }
    }

    public function test_get_status_builds_expected_get_request(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_STATUS_SUCCESS));

        $provider = $this->makeProvider(http: $http, apiKey: 'my-api-key');
        $provider->getStatus('23861959');

        $request = $http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://sms2.aliftech.net/api/v1/sms/23861959',
            (string) $request->getUri(),
        );
        self::assertSame('my-api-key', $request->getHeaderLine('X-Api-Key'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function test_get_status_url_encodes_message_id(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_STATUS_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');
        $provider->getStatus('id with spaces');

        $request = $http->lastRequest();
        self::assertSame('/api/v1/sms/id%20with%20spaces', $request->getUri()->getPath());
    }

    public function test_get_status_rejects_empty_message_id(): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http);

        $this->expectException(InvalidMessageException::class);

        try {
            $provider->getStatus('   ');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    /**
     * @return iterable<string, array{0: string|int, 1: MessageStatus}>
     */
    public static function statusMappings(): iterable
    {
        yield 'None string -> Unknown'         => ['None', MessageStatus::Unknown];
        yield 'Enroute string -> Sent'         => ['Enroute', MessageStatus::Sent];
        yield 'Delivered string -> Delivered'  => ['Delivered', MessageStatus::Delivered];
        yield 'Expired string -> Expired'      => ['Expired', MessageStatus::Expired];
        yield 'Deleted string -> Rejected'     => ['Deleted', MessageStatus::Rejected];
        yield 'Undeliverable -> Undelivered'   => ['Undeliverable', MessageStatus::Undelivered];
        yield 'Accepted string -> Sent'        => ['Accepted', MessageStatus::Sent];
        yield 'Unknown string -> Unknown'      => ['Unknown', MessageStatus::Unknown];
        yield 'Rejected string -> Rejected'    => ['Rejected', MessageStatus::Rejected];
        yield 'case insensitive string'        => ['delivered', MessageStatus::Delivered];
        yield 'numeric code 0 -> Unknown'      => [0, MessageStatus::Unknown];
        yield 'numeric code 1 -> Sent'         => [1, MessageStatus::Sent];
        yield 'numeric code 2 -> Delivered'    => [2, MessageStatus::Delivered];
        yield 'numeric code 6 -> Sent'         => [6, MessageStatus::Sent];
        yield 'numeric code 8 -> Rejected'     => [8, MessageStatus::Rejected];
        yield 'unknown string -> Unknown'      => ['Whatever', MessageStatus::Unknown];
    }

    /**
     * @param string|int $native
     */
    #[DataProvider('statusMappings')]
    public function test_get_status_maps_message_states($native, MessageStatus $expected): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(
            200,
            [],
            sprintf(
                '{"MessageId":"23861959","CommandStatus":"OK","MessageState":%s}',
                json_encode($native, JSON_THROW_ON_ERROR),
            ),
        ));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank')
            ->getStatus('23861959');

        self::assertSame($expected, $result->status);
    }

    public function test_get_status_prefers_date_done_over_date_submitted(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_STATUS_SUCCESS));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank')
            ->getStatus('23861959');

        self::assertNotNull($result->updatedAt);
        self::assertSame('2026-02-17T10:22:00', $result->updatedAt->format('Y-m-d\TH:i:s'));
    }

    public function test_get_status_falls_back_to_date_submitted_when_date_done_missing(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(
            200,
            [],
            '{"MessageId":"23861959","CommandStatus":"OK","MessageState":"Enroute","DateSubmitted":"2026-02-17T10:20:30"}',
        ));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank')
            ->getStatus('23861959');

        self::assertNotNull($result->updatedAt);
        self::assertSame('2026-02-17T10:20:30', $result->updatedAt->format('Y-m-d\TH:i:s'));
    }

    public function test_get_status_tolerates_missing_timestamps(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(
            200,
            [],
            '{"MessageId":"23861959","CommandStatus":"OK","MessageState":"Enroute"}',
        ));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank')
            ->getStatus('23861959');

        self::assertNull($result->updatedAt);
    }

    public function test_get_status_fails_when_response_has_no_message_state(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(
            200,
            [],
            '{"MessageId":"23861959","CommandStatus":"OK"}',
        ));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/MessageState/');

        $provider->getStatus('23861959');
    }

    public function test_get_status_translates_command_status_other_than_ok(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(
            200,
            [],
            '{"MessageId":"23861959","CommandStatus":"NOT_FOUND","MessageState":"Unknown"}',
        ));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        try {
            $provider->getStatus('23861959');
            self::fail('Expected ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame('NOT_FOUND', $exception->getProviderCode());
        }
    }

    public function test_get_status_translates_http_errors(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(404, [], '{"message":"Not Found"}'));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        try {
            $provider->getStatus('23861959');
            self::fail('Expected ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame('404', $exception->getProviderCode());
            self::assertStringContainsString('Not Found', $exception->getMessage());
        }
    }

    public function test_round_trip_between_send_and_status(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_SEND_SUCCESS));
        $http->enqueue(new Response(200, [], self::SAMPLE_STATUS_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'AlifBank');

        $send = $provider->send(new SmsMessage('+992900900900', 'hi'));
        $status = $provider->getStatus($send->messageId);

        self::assertSame('23861959', $send->messageId);
        self::assertSame('23861959', $status->messageId);
        self::assertSame(MessageStatus::Delivered, $status->status);
        self::assertSame(
            '/api/v1/sms/23861959',
            $http->requests[1]->getUri()->getPath(),
        );
    }

    private function makeProvider(
        ?MockHttpClient $http = null,
        string $apiKey = 'test-key',
        ?string $defaultSenderName = null,
        SmsType $defaultSmsType = SmsType::Common,
        string $baseUri = SmsGateProvider::DEFAULT_BASE_URI,
    ): SmsGateProvider {
        $factory = new Psr17Factory();

        return new SmsGateProvider(
            apiKey: $apiKey,
            defaultSenderName: $defaultSenderName,
            defaultSmsType: $defaultSmsType,
            httpClient: $http ?? new MockHttpClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUri: $baseUri,
        );
    }
}
