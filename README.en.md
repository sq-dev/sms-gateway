# sq-dev/sms-gateway

[Тоҷикӣ](README.md) | [Русский](README.ru.md) | [English](README.en.md)

`sq-dev/sms-gateway` is a PHP library that gives you a unified SMS sending API across multiple providers. It provides a stable abstraction for sending, normalized result/status objects, and an optional Laravel bridge with a facade, manager, and config-driven connections.

## What it gives you

- `Sender` with a simple API for sending SMS.
- `SendResult` and `StatusResult` with a shared result model.
- `MessageStatus` for normalized states such as `Queued`, `Sent`, and `Delivered`.
- first-class Laravel integration through the `SmsGateway` facade, config, and connection manager.
- the ability to plug in custom providers without rewriting consumer code.

## Installation

```bash
composer require sq-dev/sms-gateway
```

The library relies on a PSR-18 HTTP client. If your project already includes Guzzle, Symfony HTTP Client, or another PSR-18 client, `php-http/discovery` will auto-detect it. If not, install one alongside the package:

```bash
composer require guzzlehttp/guzzle
# or
composer require symfony/http-client
```

## Requirements

- PHP `^8.1`
- Composer
- a PSR-18 HTTP client

## Quick start

A minimal example with one of the built-in providers:

```php
use SmsGateway\Sender;
use SmsGateway\Providers\Payom\PayomSmsProvider;

$sender = new Sender(new PayomSmsProvider(
    token: $_ENV['PAYOM_JWT_TOKEN'],
    defaultSenderName: 'payom.tj',
));

$result = $sender->send('+992937123456', 'Your verification code is 1234');
```

The return value is always a `SendResult`:

```php
$result->messageId;    // provider-issued identifier
$result->status;       // MessageStatus
$result->providerName; // for example "payom"
$result->raw;          // raw provider payload for logging/debugging
```

## Core API

`Sender` is a thin layer over `SendsSmsInterface`. For most applications, these three methods are the whole surface:

```php
$sender->send(
    to: '+992937123456',
    text: 'Hello',
    from: 'ACME',
    metadata: ['client_ref' => 'r-1'],
);

$sender->sendMessage($smsMessage);

$provider = $sender->provider();
```

If the provider supports delivery tracking, you can detect it with `TracksSmsStatusInterface`:

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;

$provider = $sender->provider();

if ($provider instanceof TracksSmsStatusInterface) {
    $status = $provider->getStatus($result->messageId);

    if ($status->status->isFinal()) {
        // stop polling
    }
}
```

## Normalized results and errors

All providers are translated into the same model:

- `SendResult` for the initial send response
- `StatusResult` for status lookups
- `MessageStatus` for normalized lifecycle states
- `SmsGatewayException` as the base contract for library exceptions

Most important exception types:

- `InvalidMessageException` for invalid input or metadata
- `ProviderException` for remote API, auth, or transport failures
- `MessageNotFoundException` for unknown `messageId` values during status lookup

## Laravel

The package includes a ready-to-use Laravel integration for Laravel 10, 11, and 12. The service provider and facade are auto-discovered through `composer.json`.

### Publish the config

```bash
php artisan vendor:publish --tag=sms-gateway-config
```

That publishes `config/sms-gateway.php`. The general shape is:

```php
return [
    'default' => env('SMS_GATEWAY_CONNECTION', 'aliftech'),

    'connections' => [
        'payom' => [
            'driver' => 'payom',
            'token' => env('PAYOM_JWT_TOKEN'),
            'default_sender_name' => env('PAYOM_DEFAULT_SENDER'),
        ],
    ],
];
```

Here:

- `default` is the main connection name
- `connections` contains all named connections
- `driver` selects the built-in or custom adapter

See `config/sms-gateway.php` for the full list of supported keys and examples.

### Using the facade

```php
use SmsGateway\Laravel\Facades\SmsGateway;

SmsGateway::send('+992937123456', 'Your code is 1234');

SmsGateway::provider('payom')->send('+992937123456', 'Hello');

SmsGateway::send(
    to: '+992937123456',
    text: 'Hello',
    from: 'ACME',
    metadata: ['client_ref' => 'r-1'],
);
```

### Constructor injection

`Sender` is also bound into the container, so you can inject it directly:

```php
use SmsGateway\Sender;

final class SendOtpCommand
{
    public function __construct(
        private readonly Sender $sms,
    ) {}

    public function handle(string $phone, string $code): void
    {
        $this->sms->send($phone, "Your code is {$code}");
    }
}
```

### Custom driver

You can register your own driver with `extend()`:

```php
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\Laravel\Facades\SmsGateway;

SmsGateway::extend('custom', function ($app, array $config, string $name): SendsSmsInterface {
    return new CustomSmsProvider(...);
});
```

## Built-in providers

| Driver | Capability | Summary | Docs |
| --- | --- | --- | --- |
| `payom` | send | no status lookup support | [`docs/en/providers/payom.md`](docs/en/providers/payom.md) |
| `osonsms` | send + status | uses a composite `messageId`, which must be treated as opaque | [`docs/en/providers/osonsms.md`](docs/en/providers/osonsms.md) |
| `aliftech` | send + status | supports metadata such as `sms_type`, `priority`, and `scheduled_at` | [`docs/en/providers/aliftech.md`](docs/en/providers/aliftech.md) |

## Writing your own provider

The library is contract-first. To add a new provider:

- implement `SendsSmsInterface` for send-only providers
- implement `TracksSmsStatusInterface` for status-only providers
- implement `SmsProviderInterface` for providers that support both

The core rule is to never leak vendor-specific exceptions. Translate them into `ProviderException` or `MessageNotFoundException`, and map native provider statuses into `MessageStatus`.

## Development

```bash
composer install
composer test
```

## License

MIT
