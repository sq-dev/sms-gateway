<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\Laravel\SmsGatewayManager;
use SmsGateway\Providers\OsonSms\OsonSmsProvider;
use SmsGateway\Providers\Payom\PayomSmsProvider;
use SmsGateway\Providers\Aliftech\AliftechProvider;
use SmsGateway\Providers\Aliftech\SmsType;
use SmsGateway\Sender;
use SmsGateway\Tests\Fixtures\DummyProvider;

#[CoversClass(SmsGatewayManager::class)]
final class SmsGatewayManagerTest extends TestCase
{
    public function test_provider_returns_sender_for_default_connection(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['driver' => 'dummy'],
            ],
        ]);

        $sender = $manager->provider();

        self::assertInstanceOf(Sender::class, $sender);
        self::assertInstanceOf(DummyProvider::class, $sender->provider());
    }

    public function test_provider_returns_sender_for_named_connection(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['driver' => 'dummy'],
                'alt' => ['driver' => 'dummy', 'name' => 'other'],
            ],
        ]);

        $default = $manager->provider();
        $alt = $manager->provider('alt');

        self::assertNotSame($default, $alt);
        /** @var DummyProvider $defaultProvider */
        $defaultProvider = $default->provider();
        /** @var DummyProvider $altProvider */
        $altProvider = $alt->provider();
        self::assertInstanceOf(DummyProvider::class, $defaultProvider);
        self::assertInstanceOf(DummyProvider::class, $altProvider);
        self::assertSame('dummy', $defaultProvider->getName());
        self::assertSame('other', $altProvider->getName());
    }

    public function test_connection_is_alias_for_provider(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['driver' => 'dummy'],
            ],
        ]);

        self::assertSame($manager->provider(), $manager->connection());
        self::assertSame($manager->provider('demo'), $manager->connection('demo'));
    }

    public function test_send_delegates_to_default_connection(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['driver' => 'dummy'],
            ],
        ]);

        $result = $manager->send('+992900000000', 'Hello');

        self::assertSame('dummy', $result->providerName);

        $provider = $manager->provider()->provider();
        self::assertInstanceOf(DummyProvider::class, $provider);
        self::assertCount(1, $provider->sentMessages);
        self::assertSame('+992900000000', $provider->sentMessages[0]->to);
        self::assertSame('Hello', $provider->sentMessages[0]->text);
    }

    public function test_sent_messages_flow_optional_from_and_metadata(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['driver' => 'dummy'],
            ],
        ]);

        $manager->send(
            to: '+992900000000',
            text: 'Hi',
            from: 'ACME',
            metadata: ['priority' => 'high'],
        );

        $provider = $manager->provider()->provider();
        self::assertInstanceOf(DummyProvider::class, $provider);
        self::assertSame('ACME', $provider->sentMessages[0]->from);
        self::assertSame(['priority' => 'high'], $provider->sentMessages[0]->metadata);
    }

    public function test_resolved_senders_are_cached_per_connection(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['driver' => 'dummy'],
            ],
        ]);

        self::assertSame($manager->provider(), $manager->provider());
        self::assertSame($manager->provider('demo'), $manager->provider('demo'));
    }

    public function test_missing_default_connection_throws(): void
    {
        $manager = $this->makeManager([
            'connections' => [
                'demo' => ['driver' => 'dummy'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('default connection');

        $manager->provider();
    }

    public function test_unknown_connection_throws(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['driver' => 'dummy'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"missing"');

        $manager->provider('missing');
    }

    public function test_unknown_driver_throws(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['driver' => 'imaginary'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"imaginary"');

        $manager->provider();
    }

    public function test_connection_without_driver_key_throws(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['token' => 'oops'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('driver');

        $manager->provider();
    }

    public function test_payom_driver_builds_payom_provider(): void
    {
        $manager = new SmsGatewayManager(new Container(), [
            'default' => 'payom',
            'connections' => [
                'payom' => [
                    'driver' => 'payom',
                    'token' => 'jwt',
                    'default_sender_name' => 'payom.tj',
                ],
            ],
        ]);

        $sender = $manager->provider();

        self::assertInstanceOf(PayomSmsProvider::class, $sender->provider());
    }

    public function test_osonsms_driver_builds_osonsms_provider(): void
    {
        $manager = new SmsGatewayManager(new Container(), [
            'default' => 'oson',
            'connections' => [
                'oson' => [
                    'driver' => 'osonsms',
                    'token' => 'bearer',
                    'login' => 'acct',
                    'default_sender_name' => 'BRAND',
                ],
            ],
        ]);

        self::assertInstanceOf(OsonSmsProvider::class, $manager->provider()->provider());
    }

    public function test_aliftech_driver_builds_aliftech_provider_with_default_type(): void
    {
        $manager = new SmsGatewayManager(new Container(), [
            'default' => 'gate',
            'connections' => [
                'gate' => [
                    'driver' => 'aliftech',
                    'api_key' => 'apikey',
                    'default_sender_name' => 'AlifBank',
                ],
            ],
        ]);

        self::assertInstanceOf(AliftechProvider::class, $manager->provider()->provider());
    }

    public function test_aliftech_driver_accepts_default_sms_type_as_string_name(): void
    {
        $manager = new SmsGatewayManager(new Container(), [
            'default' => 'gate',
            'connections' => [
                'gate' => [
                    'driver' => 'aliftech',
                    'api_key' => 'apikey',
                    'default_sender_name' => 'AlifBank',
                    'default_sms_type' => 'otp',
                ],
            ],
        ]);

        self::assertInstanceOf(AliftechProvider::class, $manager->provider()->provider());
    }

    public function test_aliftech_driver_accepts_default_sms_type_as_enum(): void
    {
        $manager = new SmsGatewayManager(new Container(), [
            'default' => 'gate',
            'connections' => [
                'gate' => [
                    'driver' => 'aliftech',
                    'api_key' => 'apikey',
                    'default_sender_name' => 'AlifBank',
                    'default_sms_type' => SmsType::Batch,
                ],
            ],
        ]);

        self::assertInstanceOf(AliftechProvider::class, $manager->provider()->provider());
    }

    public function test_aliftech_driver_accepts_default_sms_type_as_integer(): void
    {
        $manager = new SmsGatewayManager(new Container(), [
            'default' => 'gate',
            'connections' => [
                'gate' => [
                    'driver' => 'aliftech',
                    'api_key' => 'apikey',
                    'default_sender_name' => 'AlifBank',
                    'default_sms_type' => 2,
                ],
            ],
        ]);

        self::assertInstanceOf(AliftechProvider::class, $manager->provider()->provider());
    }

    public function test_invalid_aliftech_default_sms_type_throws(): void
    {
        $manager = new SmsGatewayManager(new Container(), [
            'default' => 'gate',
            'connections' => [
                'gate' => [
                    'driver' => 'aliftech',
                    'api_key' => 'apikey',
                    'default_sender_name' => 'AlifBank',
                    'default_sms_type' => 'nope',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default_sms_type');

        $manager->provider();
    }

    public function test_extend_registers_custom_driver(): void
    {
        $manager = new SmsGatewayManager(new Container(), [
            'default' => 'custom',
            'connections' => [
                'custom' => ['driver' => 'spy', 'tag' => 'first'],
            ],
        ]);

        $manager->extend('spy', function ($app, array $config, string $name): SendsSmsInterface {
            return new DummyProvider(name: 'spy:' . $name . ':' . ($config['tag'] ?? ''));
        });

        /** @var DummyProvider $provider */
        $provider = $manager->provider()->provider();
        self::assertInstanceOf(DummyProvider::class, $provider);
        self::assertSame('spy:custom:first', $provider->getName());
    }

    public function test_extend_receives_container_instance(): void
    {
        $container = new Container();
        $container->instance('marker', 'hello');

        $manager = new SmsGatewayManager($container, [
            'default' => 'custom',
            'connections' => [
                'custom' => ['driver' => 'spy'],
            ],
        ]);

        /** @var ContainerContract|null $captured */
        $captured = null;
        $manager->extend('spy', function ($app, array $config, string $name) use (&$captured): SendsSmsInterface {
            $captured = $app;
            return new DummyProvider();
        });

        $manager->provider();

        self::assertInstanceOf(ContainerContract::class, $captured);
        self::assertSame('hello', $captured->make('marker'));
    }

    public function test_set_default_connection_switches_default(): void
    {
        $manager = $this->makeManager([
            'default' => 'demo',
            'connections' => [
                'demo' => ['driver' => 'dummy', 'name' => 'first'],
                'alt' => ['driver' => 'dummy', 'name' => 'second'],
            ],
        ]);

        /** @var DummyProvider $first */
        $first = $manager->provider()->provider();
        self::assertInstanceOf(DummyProvider::class, $first);
        self::assertSame('first', $first->getName());

        $manager->setDefaultConnection('alt');

        self::assertSame('alt', $manager->getDefaultConnection());
        /** @var DummyProvider $second */
        $second = $manager->provider()->provider();
        self::assertInstanceOf(DummyProvider::class, $second);
        self::assertSame('second', $second->getName());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeManager(array $config): SmsGatewayManager
    {
        $manager = new SmsGatewayManager(new Container(), $config);

        $manager->extend('dummy', static function ($app, array $driverConfig): SendsSmsInterface {
            $name = isset($driverConfig['name']) ? (string) $driverConfig['name'] : 'dummy';
            return new DummyProvider(name: $name);
        });

        return $manager;
    }
}
