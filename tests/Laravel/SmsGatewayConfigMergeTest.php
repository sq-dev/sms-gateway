<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Laravel;

use PHPUnit\Framework\Attributes\CoversClass;
use SmsGateway\Laravel\SmsGatewayServiceProvider;

/**
 * Verifies that `mergeConfigFrom` delivers the package defaults end-to-end.
 *
 * Deliberately does not override `defineEnvironment()` so the test runs in
 * the "user has not published or touched config" scenario, which is where
 * `mergeConfigFrom` is supposed to take effect.
 */
#[CoversClass(SmsGatewayServiceProvider::class)]
final class SmsGatewayConfigMergeTest extends TestCase
{
    public function test_default_connection_key_is_available(): void
    {
        $default = $this->app['config']->get('sms-gateway.default');

        self::assertIsString($default);
        self::assertNotSame('', $default);
    }

    public function test_builtin_connections_are_merged_from_package_config(): void
    {
        $connections = $this->app['config']->get('sms-gateway.connections');

        self::assertIsArray($connections);
        self::assertArrayHasKey('payom', $connections);
        self::assertArrayHasKey('osonsms', $connections);
        self::assertArrayHasKey('aliftech', $connections);

        foreach (['payom', 'osonsms', 'aliftech'] as $name) {
            self::assertIsArray($connections[$name]);
            self::assertArrayHasKey('driver', $connections[$name]);
            self::assertSame($name, $connections[$name]['driver']);
        }
    }
}
