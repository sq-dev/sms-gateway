<?php

declare(strict_types=1);

namespace SmsGateway\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SmsGateway\Sender;

/**
 * Laravel service provider for `sq-dev/sms-gateway`.
 *
 * Registers the {@see SmsGatewayManager} as a singleton, exposes the default
 * configured {@see Sender} to the container for constructor injection, and
 * wires up configuration publishing so apps can customise connections in
 * their own `config/sms-gateway.php`.
 *
 * Auto-discovered by Laravel via `composer.json -> extra.laravel.providers`.
 */
final class SmsGatewayServiceProvider extends ServiceProvider
{
    private const CONFIG_KEY = 'sms-gateway';

    private const PUBLISH_TAG = 'sms-gateway-config';

    private const MANAGER_ALIAS = 'sms-gateway';

    public function register(): void
    {
        $this->mergeConfigFrom($this->packageConfigPath(), self::CONFIG_KEY);

        $this->app->singleton(SmsGatewayManager::class, function (Application $app): SmsGatewayManager {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new SmsGatewayManager(
                $app,
                (array) $config->get(self::CONFIG_KEY, []),
            );
        });

        $this->app->alias(SmsGatewayManager::class, self::MANAGER_ALIAS);

        $this->app->bind(Sender::class, static function (Application $app): Sender {
            return $app->make(SmsGatewayManager::class)->provider();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->packageConfigPath() => $this->applicationConfigPath(),
            ], self::PUBLISH_TAG);
        }
    }

    /**
     * Absolute path to the config file shipped with the package.
     */
    private function packageConfigPath(): string
    {
        return dirname(__DIR__, 2) . '/config/sms-gateway.php';
    }

    /**
     * Absolute path where the Laravel app expects `sms-gateway.php` to live
     * after `php artisan vendor:publish`.
     */
    private function applicationConfigPath(): string
    {
        if (function_exists('config_path')) {
            return config_path('sms-gateway.php');
        }

        return $this->app->basePath('config/sms-gateway.php');
    }
}
