<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Laravel;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SmsGateway\Laravel\Facades\SmsGateway;
use SmsGateway\Laravel\SmsGatewayServiceProvider;

/**
 * Shared base class for Laravel integration tests.
 *
 * Boots a minimal Laravel application via `orchestra/testbench` so we can
 * exercise the service provider, facade, and container bindings without
 * requiring a real Laravel app. Only the wiring is covered here -
 * provider-level HTTP behavior lives in `tests/Unit/Providers/...`.
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SmsGatewayServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'SmsGateway' => SmsGateway::class,
        ];
    }
}
