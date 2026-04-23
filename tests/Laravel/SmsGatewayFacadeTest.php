<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Laravel;

use PHPUnit\Framework\Attributes\CoversClass;
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Laravel\Facades\SmsGateway;
use SmsGateway\Laravel\SmsGatewayManager;
use SmsGateway\Providers\OsonSms\OsonSmsProvider;
use SmsGateway\Providers\Payom\PayomSmsProvider;
use SmsGateway\Sender;
use SmsGateway\Tests\Fixtures\DummyProvider;

#[CoversClass(SmsGateway::class)]
final class SmsGatewayFacadeTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('sms-gateway', [
            'default' => 'inbox',
            'connections' => [
                'inbox' => ['driver' => 'dummy', 'tag' => 'primary'],
                'secondary' => ['driver' => 'dummy', 'tag' => 'backup'],
                'payom' => [
                    'driver' => 'payom',
                    'token' => 'fake-jwt-for-tests',
                    'default_sender_name' => 'test-sender',
                ],
                'osonsms' => [
                    'driver' => 'osonsms',
                    'token' => 'fake-oson',
                    'login' => 'fake-login',
                    'default_sender_name' => 'BRAND',
                ],
            ],
        ]);

        $app->resolving(SmsGatewayManager::class, static function (SmsGatewayManager $manager): void {
            $manager->extend('dummy', static function ($container, array $config, string $name): SendsSmsInterface {
                return new DummyProvider(name: 'dummy:' . $name . ':' . ($config['tag'] ?? ''));
            });
        });
    }

    public function test_facade_accessor_returns_the_manager_singleton(): void
    {
        self::assertSame(
            $this->app->make(SmsGatewayManager::class),
            SmsGateway::getFacadeRoot(),
        );
    }

    public function test_facade_send_delegates_to_default_connection(): void
    {
        $result = SmsGateway::send('+992900000000', 'Hello');

        self::assertSame('dummy:inbox:primary', $result->providerName);
        self::assertSame(MessageStatus::Queued, $result->status);

        $provider = SmsGateway::provider()->provider();
        self::assertInstanceOf(DummyProvider::class, $provider);
        self::assertCount(1, $provider->sentMessages);
        self::assertSame('+992900000000', $provider->sentMessages[0]->to);
        self::assertSame('Hello', $provider->sentMessages[0]->text);
    }

    public function test_facade_provider_returns_named_sender(): void
    {
        $default = SmsGateway::provider();
        $secondary = SmsGateway::provider('secondary');

        self::assertNotSame($default, $secondary);
        self::assertInstanceOf(Sender::class, $secondary);

        $dummy = $secondary->provider();
        self::assertInstanceOf(DummyProvider::class, $dummy);
        /** @var DummyProvider $dummy */
        self::assertSame('dummy:secondary:backup', $dummy->getName());
    }

    public function test_facade_connection_alias_matches_provider(): void
    {
        self::assertSame(SmsGateway::provider(), SmsGateway::connection());
        self::assertSame(SmsGateway::provider('secondary'), SmsGateway::connection('secondary'));
    }

    public function test_facade_supports_builtin_payom_connection(): void
    {
        self::assertInstanceOf(
            PayomSmsProvider::class,
            SmsGateway::provider('payom')->provider(),
        );
    }

    public function test_facade_supports_builtin_osonsms_connection(): void
    {
        self::assertInstanceOf(
            OsonSmsProvider::class,
            SmsGateway::provider('osonsms')->provider(),
        );
    }

    public function test_facade_send_message_delegates_to_default(): void
    {
        $result = SmsGateway::sendMessage(new \SmsGateway\DTO\SmsMessage('+992900000000', 'Ping'));

        self::assertSame('dummy:inbox:primary', $result->providerName);
    }
}
