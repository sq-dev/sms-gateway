<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit\Providers\OsonSms;

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
use SmsGateway\Providers\OsonSms\OsonSmsProvider;
use SmsGateway\Tests\Fixtures\MockHttpClient;
use SmsGateway\Tests\Fixtures\TransportException;

#[CoversClass(OsonSmsProvider::class)]
final class OsonSmsProviderTest extends TestCase
{
    private const SAMPLE_SEND_SUCCESS = <<<'JSON'
    {
      "status": "ok",
      "txn_id": "test-txn-001",
      "msg_id": "server-msg-987654",
      "smsc_msg_parts": 1,
      "timestamp": "2026-02-08 12:00:00"
    }
    JSON;

    private const SAMPLE_STATUS_SUCCESS = <<<'JSON'
    {
      "status": "DELIVERED",
      "timestamp": "2026-02-08 12:01:00"
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
        self::assertSame('osonsms', $this->makeProvider()->getName());
        self::assertSame(OsonSmsProvider::PROVIDER_NAME, $this->makeProvider()->getName());
    }

    public function test_can_be_constructed_with_only_business_config(): void
    {
        $provider = new OsonSmsProvider(
            token: 'bearer',
            login: 'account-login',
            defaultSenderName: 'SENDER',
        );

        self::assertSame(OsonSmsProvider::PROVIDER_NAME, $provider->getName());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?string, 3: ?string, 4: string}>
     */
    public static function invalidConstructorInputs(): iterable
    {
        yield 'empty token'         => ['   ', 'login', null, null, 'token'];
        yield 'empty login'         => ['token', '  ', null, null, 'login'];
        yield 'blank default sender'=> ['token', 'login', '   ', null, 'sender'];
        yield 'empty base uri'      => ['token', 'login', null, '   ', 'base URI'];
    }

    #[DataProvider('invalidConstructorInputs')]
    public function test_rejects_invalid_constructor_input(
        string $token,
        string $login,
        ?string $defaultSenderName,
        ?string $baseUri,
        string $expectedMessageFragment,
    ): void {
        $factory = new Psr17Factory();
        $http = new MockHttpClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessageFragment, '/') . '/i');

        new OsonSmsProvider(
            token: $token,
            login: $login,
            defaultSenderName: $defaultSenderName,
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUri: $baseUri ?? OsonSmsProvider::DEFAULT_BASE_URI,
        );
    }

    public function test_send_builds_expected_get_request(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, token: 'jwt', login: 'client-login');
        $provider->send(new SmsMessage(
            to: '+992900000000',
            text: 'Hello, world',
            from: 'BRAND',
            metadata: ['txn_id' => 'test-txn-001'],
        ));

        $request = $http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('Bearer jwt', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));

        $uri = $request->getUri();
        self::assertSame('api.osonsms.com', $uri->getHost());
        self::assertSame('/sendsms_v1.php', $uri->getPath());

        parse_str($uri->getQuery(), $query);
        self::assertSame([
            'from' => 'BRAND',
            'phone_number' => '992900000000',
            'msg' => 'Hello, world',
            'login' => 'client-login',
            'txn_id' => 'test-txn-001',
        ], $query);
    }

    public function test_send_prefers_message_sender_over_default(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'DEFAULT');
        $provider->send(new SmsMessage('+992900000000', 'hello', from: 'OVERRIDE'));

        parse_str($http->lastRequest()->getUri()->getQuery(), $query);

        self::assertSame('OVERRIDE', $query['from']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function phoneNormalizationCases(): iterable
    {
        yield 'plus prefix'              => ['+992900000000', '992900000000'];
        yield 'already normalized'       => ['992900000000', '992900000000'];
        yield 'spaces and hyphens'       => ['+992 90 000-00-00', '992900000000'];
        yield 'parentheses'              => ['+992 (90) 000-00-00', '992900000000'];
    }

    #[DataProvider('phoneNormalizationCases')]
    public function test_send_normalizes_phone_number(string $input, string $expectedQueryValue): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');
        $provider->send(new SmsMessage($input, 'hi'));

        parse_str($http->lastRequest()->getUri()->getQuery(), $query);

        self::assertSame($expectedQueryValue, $query['phone_number']);
    }

    public function test_send_auto_generates_txn_id_when_not_provided(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');
        $provider->send(new SmsMessage('+992900000000', 'hi'));

        parse_str($http->lastRequest()->getUri()->getQuery(), $query);
        self::assertIsString($query['txn_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $query['txn_id']);
    }

    public function test_send_uses_txn_id_from_metadata(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');
        $provider->send(new SmsMessage(
            '+992900000000',
            'hi',
            metadata: ['txn_id' => 'my-custom-txn'],
        ));

        parse_str($http->lastRequest()->getUri()->getQuery(), $query);

        self::assertSame('my-custom-txn', $query['txn_id']);
    }

    public function test_send_rejects_txn_id_containing_separator(): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageMatches('/must not contain/');

        try {
            $provider->send(new SmsMessage(
                '+992900000000',
                'hi',
                metadata: ['txn_id' => 'bad|txn'],
            ));
        } finally {
            self::assertSame([], $http->requests, 'No request should have been dispatched.');
        }
    }

    public function test_send_forwards_channel_and_confidential_metadata(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');
        $provider->send(new SmsMessage(
            '+992900000000',
            'hi',
            metadata: [
                'channel' => 'telegram',
                'is_confidential' => true,
            ],
        ));

        parse_str($http->lastRequest()->getUri()->getQuery(), $query);

        self::assertSame('telegram', $query['channel']);
        self::assertSame('true', $query['is_confidential']);
    }

    public function test_send_encodes_is_confidential_false_explicitly(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SEND_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');
        $provider->send(new SmsMessage(
            '+992900000000',
            'hi',
            metadata: ['is_confidential' => false],
        ));

        parse_str($http->lastRequest()->getUri()->getQuery(), $query);

        self::assertSame('false', $query['is_confidential']);
    }

    public function test_send_without_any_sender_throws_invalid_message_exception(): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http);

        $this->expectException(InvalidMessageException::class);

        try {
            $provider->send(new SmsMessage('+992900000000', 'hi'));
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_send_returns_composite_message_id(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SEND_SUCCESS));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'BRAND')
            ->send(new SmsMessage(
                '+992900000000',
                'hi',
                metadata: ['txn_id' => 'test-txn-001'],
            ));

        self::assertSame('test-txn-001|server-msg-987654', $result->messageId);
        self::assertSame(MessageStatus::Queued, $result->status);
        self::assertSame('osonsms', $result->providerName);
        self::assertSame('test-txn-001', $result->raw['txn_id']);
        self::assertSame('server-msg-987654', $result->raw['msg_id']);
    }

    public function test_send_accepts_numeric_msg_id(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], '{"status":"ok","msg_id":123456,"timestamp":"2026-02-08 12:00:00"}'));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'BRAND')
            ->send(new SmsMessage(
                '+992900000000',
                'hi',
                metadata: ['txn_id' => 'custom'],
            ));

        self::assertSame('custom|123456', $result->messageId);
    }

    public function test_send_fails_when_response_has_no_msg_id(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], '{"status":"ok"}'));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/msg_id/');

        $provider->send(new SmsMessage('+992900000000', 'hi'));
    }

    public function test_send_translates_transport_failure_into_provider_exception(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new TransportException(new Request('GET', 'https://api.osonsms.com/sendsms_v1.php')));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');

        try {
            $provider->send(new SmsMessage('+992900000000', 'hi'));
            self::fail('Expected ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame('osonsms', $exception->getProviderName());
            self::assertNull($exception->getProviderCode());
            self::assertInstanceOf(TransportException::class, $exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: string}>
     */
    public static function errorResponses(): iterable
    {
        yield 'duplicate txn_id' => [
            409,
            '{"error":{"code":108,"msg":"Duplicate txn_id. It should be unique.","timestamp":"2026-02-08 12:00:00"}}',
            '108',
        ];
        yield 'validation error' => [
            422,
            '{"error":{"code":100,"msg":"One of the mandatory variables (from,phone_number,msg,login,txn_id) not set.","timestamp":"2026-02-08 12:00:00"}}',
            '100',
        ];
        yield 'incorrect sender' => [
            422,
            '{"error":{"code":107,"msg":"Incorrect sender","timestamp":"2026-02-08 12:00:00"}}',
            '107',
        ];
        yield 'incorrect authorization' => [
            422,
            '{"error":{"code":106,"msg":"Incorrect Authorization","timestamp":"2026-02-08 12:00:00"}}',
            '106',
        ];
        yield 'inactive account' => [
            401,
            '{"error":{"code":105,"msg":"Inactive or non-existent account","timestamp":"2026-02-08 12:00:00"}}',
            '105',
        ];
        yield 'balance exhausted' => [
            402,
            '{"error":{"code":119,"msg":"Balance overdraft limit has reached. Please top up your balance.","timestamp":"2026-02-08 12:00:00"}}',
            '119',
        ];
        yield 'ip not whitelisted' => [
            401,
            '{"error":{"code":114,"msg":"Host is not in whitelist","timestamp":"2026-02-08 12:00:00"}}',
            '114',
        ];
        yield 'unable to store' => [
            500,
            '{"error":{"code":109,"msg":"Unable to store message.","timestamp":"2026-02-08 12:00:00"}}',
            '109',
        ];
        yield 'send failure' => [
            501,
            '{"error":{"code":112,"msg":"Error while sending sms.","timestamp":"2026-02-08 12:00:00"}}',
            '112',
        ];
        yield 'smsc unreachable' => [
            599,
            '{"error":{"code":113,"msg":"Unable to connect to smsc host.","timestamp":"2026-02-08 12:00:00"}}',
            '113',
        ];
    }

    #[DataProvider('errorResponses')]
    public function test_send_extracts_internal_error_code_from_error_object(
        int $statusCode,
        string $body,
        string $expectedProviderCode,
    ): void {
        $http = new MockHttpClient();
        $http->enqueue(new Response($statusCode, [], $body));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');

        try {
            $provider->send(new SmsMessage('+992900000000', 'hi'));
            self::fail('Expected ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame('osonsms', $exception->getProviderName());
            self::assertSame($expectedProviderCode, $exception->getProviderCode());
        }
    }

    public function test_send_falls_back_to_http_status_when_error_object_missing(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(503, [], '<html>Gateway Timeout</html>'));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');

        try {
            $provider->send(new SmsMessage('+992900000000', 'hi'));
            self::fail('Expected ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame('503', $exception->getProviderCode());
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: MessageStatus}>
     */
    public static function statusMappings(): iterable
    {
        yield 'ENROUTE -> Queued'                  => ['ENROUTE', MessageStatus::Queued];
        yield 'ACCEPTED -> Sent'                   => ['ACCEPTED', MessageStatus::Sent];
        yield 'DELIVERED -> Delivered'             => ['DELIVERED', MessageStatus::Delivered];
        yield 'EXPIRED -> Expired'                 => ['EXPIRED', MessageStatus::Expired];
        yield 'UNDELIVERABLE -> Undelivered'       => ['UNDELIVERABLE', MessageStatus::Undelivered];
        yield 'DELETED -> Rejected'                => ['DELETED', MessageStatus::Rejected];
        yield 'REJECTED -> Rejected'               => ['REJECTED', MessageStatus::Rejected];
        yield 'UNKNOWN -> Unknown'                 => ['UNKNOWN', MessageStatus::Unknown];
        yield 'case insensitive'                   => ['delivered', MessageStatus::Delivered];
        yield 'unexpected value falls back'        => ['WHATEVER', MessageStatus::Unknown];
    }

    #[DataProvider('statusMappings')]
    public function test_get_status_maps_delivery_statuses(
        string $native,
        MessageStatus $expected,
    ): void {
        $http = new MockHttpClient();
        $http->enqueue(new Response(
            200,
            [],
            sprintf('{"status":%s,"timestamp":"2026-02-08 12:00:00"}', json_encode($native, JSON_THROW_ON_ERROR)),
        ));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'BRAND')
            ->getStatus('test-txn-001|server-msg-987654');

        self::assertSame($expected, $result->status);
    }

    public function test_get_status_builds_expected_get_request(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_STATUS_SUCCESS));

        $provider = $this->makeProvider(http: $http, token: 'jwt', login: 'client-login');
        $provider->getStatus('test-txn-001|server-msg-987654');

        $request = $http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('Bearer jwt', $request->getHeaderLine('Authorization'));

        $uri = $request->getUri();
        self::assertSame('/query_sms.php', $uri->getPath());

        parse_str($uri->getQuery(), $query);
        self::assertSame([
            'login' => 'client-login',
            'txn_id' => 'test-txn-001',
            'msg_id' => 'server-msg-987654',
        ], $query);
    }

    public function test_get_status_parses_timestamp_when_present(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], self::SAMPLE_STATUS_SUCCESS));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'BRAND')
            ->getStatus('test-txn-001|server-msg-987654');

        self::assertNotNull($result->updatedAt);
        self::assertSame(
            '2026-02-08 12:01:00',
            $result->updatedAt->format('Y-m-d H:i:s'),
        );
    }

    public function test_get_status_tolerates_missing_or_invalid_timestamp(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], '{"status":"DELIVERED"}'));

        $result = $this->makeProvider(http: $http, defaultSenderName: 'BRAND')
            ->getStatus('test-txn-001|server-msg-987654');

        self::assertNull($result->updatedAt);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedMessageIds(): iterable
    {
        yield 'missing separator' => ['just-a-plain-id'];
        yield 'empty txn part'    => ['|server-msg-1'];
        yield 'empty msg part'    => ['test-txn|'];
        yield 'both empty'        => ['|'];
    }

    #[DataProvider('malformedMessageIds')]
    public function test_get_status_rejects_malformed_message_id(string $messageId): void
    {
        $http = new MockHttpClient();
        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');

        $this->expectException(InvalidMessageException::class);

        try {
            $provider->getStatus($messageId);
        } finally {
            self::assertSame([], $http->requests, 'No request should have been dispatched.');
        }
    }

    public function test_get_status_fails_when_response_has_no_status_field(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(200, [], '{"timestamp":"2026-02-08 12:00:00"}'));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/status/');

        $provider->getStatus('test-txn-001|server-msg-987654');
    }

    public function test_get_status_translates_http_errors_into_provider_exception(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(
            422,
            [],
            '{"error":{"code":106,"msg":"Incorrect Authorization","timestamp":"2026-02-08 12:00:00"}}',
        ));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND');

        try {
            $provider->getStatus('test-txn-001|server-msg-987654');
            self::fail('Expected ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame('osonsms', $exception->getProviderName());
            self::assertSame('106', $exception->getProviderCode());
            self::assertStringContainsString('status', $exception->getMessage());
        }
    }

    public function test_round_trip_between_send_and_status_with_same_message_id(): void
    {
        $http = new MockHttpClient();
        $http->enqueue(new Response(201, [], self::SAMPLE_SEND_SUCCESS));
        $http->enqueue(new Response(200, [], self::SAMPLE_STATUS_SUCCESS));

        $provider = $this->makeProvider(http: $http, defaultSenderName: 'BRAND', login: 'client-login');

        $sendResult = $provider->send(new SmsMessage(
            '+992900000000',
            'hi',
            metadata: ['txn_id' => 'test-txn-001'],
        ));

        $statusResult = $provider->getStatus($sendResult->messageId);

        self::assertSame($sendResult->messageId, $statusResult->messageId);

        parse_str($http->requests[1]->getUri()->getQuery(), $statusQuery);
        self::assertSame('test-txn-001', $statusQuery['txn_id']);
        self::assertSame('server-msg-987654', $statusQuery['msg_id']);
    }

    private function makeProvider(
        ?MockHttpClient $http = null,
        string $token = 'jwt',
        string $login = 'test-login',
        ?string $defaultSenderName = null,
        string $baseUri = OsonSmsProvider::DEFAULT_BASE_URI,
    ): OsonSmsProvider {
        $factory = new Psr17Factory();

        return new OsonSmsProvider(
            token: $token,
            login: $login,
            defaultSenderName: $defaultSenderName,
            httpClient: $http ?? new MockHttpClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUri: $baseUri,
        );
    }
}
