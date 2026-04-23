<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Laravel;

use PHPUnit\Framework\Attributes\CoversClass;
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\Laravel\SmsGatewayManager;
use SmsGateway\Laravel\SmsGatewayServiceProvider;
use SmsGateway\Providers\Payom\PayomSmsProvider;
use SmsGateway\Sender;

#[CoversClass(SmsGatewayServiceProvider::class)]
final class SmsGatewayServiceProviderTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('sms-gateway', [
            'default' => 'payom',
            'connections' => [
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
    }

    public function test_manager_is_registered_as_a_singleton(): void
    {
        $first = $this->app->make(SmsGatewayManager::class);
        $second = $this->app->make(SmsGatewayManager::class);

        self::assertInstanceOf(SmsGatewayManager::class, $first);
        self::assertSame(
            $first,
            $second,
            'SmsGatewayManager must be bound as a singleton so Sender caches survive across calls.',
        );
    }

    public function test_manager_is_available_via_string_alias(): void
    {
        $aliased = $this->app->make('sms-gateway');

        self::assertInstanceOf(SmsGatewayManager::class, $aliased);
        self::assertSame($this->app->make(SmsGatewayManager::class), $aliased);
    }

    public function test_default_sender_is_resolvable_from_container(): void
    {
        $sender = $this->app->make(Sender::class);

        self::assertInstanceOf(Sender::class, $sender);
        self::assertInstanceOf(SendsSmsInterface::class, $sender->provider());
        self::assertInstanceOf(PayomSmsProvider::class, $sender->provider());
    }

    public function test_default_sender_reflects_runtime_connection_changes(): void
    {
        $manager = $this->app->make(SmsGatewayManager::class);

        self::assertInstanceOf(
            PayomSmsProvider::class,
            $this->app->make(Sender::class)->provider(),
        );

        $manager->setDefaultConnection('osonsms');

        self::assertSame(
            $manager->provider('osonsms'),
            $this->app->make(Sender::class),
            'Resolving Sender::class from the container must follow the manager default connection.',
        );
    }

    public function test_package_ships_default_config_with_all_builtin_connections(): void
    {
        $packageConfig = require dirname(__DIR__, 2) . '/config/sms-gateway.php';

        self::assertIsArray($packageConfig);
        self::assertArrayHasKey('default', $packageConfig);
        self::assertArrayHasKey('connections', $packageConfig);
        self::assertIsArray($packageConfig['connections']);
        self::assertArrayHasKey('payom', $packageConfig['connections']);
        self::assertArrayHasKey('osonsms', $packageConfig['connections']);
        self::assertArrayHasKey('aliftech', $packageConfig['connections']);

        foreach ($packageConfig['connections'] as $name => $connection) {
            self::assertIsArray($connection, sprintf('Connection "%s" must be an array.', $name));
            self::assertArrayHasKey('driver', $connection, sprintf('Connection "%s" must declare a driver.', $name));
        }
    }

    public function test_package_config_file_is_tagged_for_publishing(): void
    {
        $paths = SmsGatewayServiceProvider::pathsToPublish(
            SmsGatewayServiceProvider::class,
            'sms-gateway-config',
        );

        self::assertNotEmpty(
            $paths,
            'Service provider must register the config under the "sms-gateway-config" publish tag.',
        );

        $expectedSource = realpath(__DIR__ . '/../../config/sms-gateway.php');
        self::assertNotFalse($expectedSource);
        self::assertContains($expectedSource, array_map(
            static fn (string $path): string => realpath($path) !== false ? realpath($path) : $path,
            array_keys($paths),
        ));
    }
}
