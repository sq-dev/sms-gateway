<?php

declare(strict_types=1);

namespace SmsGateway\Laravel\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use SmsGateway\DTO\SendResult;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\Laravel\SmsGatewayManager;
use SmsGateway\Sender;

/**
 * Laravel facade proxying to the {@see SmsGatewayManager}.
 *
 * Registered automatically as the `SmsGateway` alias through
 * `composer.json -> extra.laravel.aliases`, so application code can write:
 *
 * ```php
 * use SmsGateway;
 *
 * SmsGateway::send('+992...', 'Hi');
 * SmsGateway::provider('payom')->send('+992...', 'Hi');
 * ```
 *
 * @method static SendResult send(string $to, string $text, ?string $from = null, array $metadata = [])
 * @method static SendResult sendMessage(SmsMessage $message)
 * @method static Sender     provider(?string $name = null)
 * @method static Sender     connection(?string $name = null)
 * @method static string     getDefaultConnection()
 * @method static void       setDefaultConnection(string $name)
 * @method static void       extend(string $driver, Closure $factory)
 *
 * @see SmsGatewayManager
 */
final class SmsGateway extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SmsGatewayManager::class;
    }
}
