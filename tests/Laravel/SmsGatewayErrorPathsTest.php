<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Laravel;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use SmsGateway\Laravel\Facades\SmsGateway;
use SmsGateway\Laravel\SmsGatewayManager;
use SmsGateway\Laravel\SmsGatewayServiceProvider;
use SmsGateway\Sender;

/**
 * Integration-level tests that confirm misconfiguration surfaces clear,
 * typed exceptions through the Laravel facade and container resolution.
 *
 * The manager already has fine-grained unit tests for these scenarios -
 * these tests verify the exceptions still surface correctly once the
 * service provider + facade are in the call path.
 */
#[CoversClass(SmsGatewayServiceProvider::class)]
#[CoversClass(SmsGateway::class)]
final class SmsGatewayErrorPathsTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('sms-gateway', [
            'default' => 'primary',
            'connections' => [
                'primary' => [
                    'driver' => 'payom',
                    'token' => 'fake-jwt-for-tests',
                    'default_sender_name' => 'test-sender',
                ],
                'broken' => [
                    'driver' => 'unknown-driver',
                ],
                'missing-driver' => [
                    'token' => 'something',
                ],
            ],
        ]);
    }

    public function test_facade_provider_throws_for_unknown_connection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"does-not-exist"');

        SmsGateway::provider('does-not-exist');
    }

    public function test_facade_provider_throws_for_unknown_driver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"unknown-driver"');

        SmsGateway::provider('broken');
    }

    public function test_facade_provider_throws_when_driver_key_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('driver');

        SmsGateway::provider('missing-driver');
    }

    public function test_resolving_sender_without_default_connection_throws(): void
    {
        $this->app['config']->set('sms-gateway.default', null);
        $this->app->forgetInstance(SmsGatewayManager::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('default connection');

        $this->app->make(Sender::class);
    }
}
