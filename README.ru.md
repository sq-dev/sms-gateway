# sq-dev/sms-gateway

[Тоҷикӣ](README.md) | [Русский](README.ru.md) | [English](README.en.md)

`sq-dev/sms-gateway` — это PHP-библиотека с единым API для отправки SMS через разных провайдеров. Она дает стабильный слой абстракции для отправки, нормализованные результаты и статусы доставки, а в Laravel добавляет готовые facade, manager и config.

## Что дает библиотека

- `Sender` с простым API для отправки SMS.
- `SendResult` и `StatusResult` с единым форматом результата.
- `MessageStatus` с общими статусами вроде `Queued`, `Sent`, `Delivered`.
- готовую интеграцию с Laravel через `SmsGateway` facade, config и connection manager.
- возможность подключать собственные провайдеры без переписывания клиентского кода.

## Установка

```bash
composer require sq-dev/sms-gateway
```

Библиотека опирается на PSR-18 HTTP client. Если в проекте уже есть Guzzle, Symfony HTTP Client или любой другой PSR-18 клиент, `php-http/discovery` найдет его автоматически. Если клиента нет, установите один из них:

```bash
composer require guzzlehttp/guzzle
# или
composer require symfony/http-client
```

## Требования

- PHP `^8.1`
- Composer
- любой PSR-18 HTTP client

## Быстрый старт

Простой пример с одним из встроенных провайдеров:

```php
use SmsGateway\Sender;
use SmsGateway\Providers\Payom\PayomSmsProvider;

$sender = new Sender(new PayomSmsProvider(
    token: $_ENV['PAYOM_JWT_TOKEN'],
    defaultSenderName: 'payom.tj',
));

$result = $sender->send('+992937123456', 'Ваш код подтверждения: 1234');
```

Результат всегда имеет тип `SendResult`:

```php
$result->messageId;    // ID, выданный провайдером
$result->status;       // MessageStatus
$result->providerName; // например "payom"
$result->raw;          // исходный ответ провайдера для debug/log
```

## Основной API

`Sender` — это тонкий слой поверх `SendsSmsInterface`. В большинстве случаев достаточно трех методов:

```php
$sender->send(
    to: '+992937123456',
    text: 'Привет',
    from: 'ACME',
    metadata: ['client_ref' => 'r-1'],
);

$sender->sendMessage($smsMessage);

$provider = $sender->provider();
```

Если провайдер умеет отслеживать статус, это проверяется через `TracksSmsStatusInterface`:

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;

$provider = $sender->provider();

if ($provider instanceof TracksSmsStatusInterface) {
    $status = $provider->getStatus($result->messageId);

    if ($status->status->isFinal()) {
        // остановить polling
    }
}
```

## Единая модель результатов и ошибок

Все провайдеры приводятся к одной модели:

- `SendResult` для результата отправки
- `StatusResult` для проверки статуса
- `MessageStatus` для общих состояний
- `SmsGatewayException` как базовый контракт для всех исключений библиотеки

Основные исключения:

- `InvalidMessageException` для невалидного input или metadata
- `ProviderException` для ошибок remote API, auth и transport
- `MessageNotFoundException` для status lookup с неизвестным `messageId`

## Laravel

Библиотека поставляется с готовой интеграцией для Laravel 10, 11 и 12. Service provider и facade регистрируются автоматически через `composer.json`.

### Публикация config

```bash
php artisan vendor:publish --tag=sms-gateway-config
```

После этого появится `config/sms-gateway.php`. Общая форма конфигурации такая:

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

Здесь:

- `default` — основное connection
- `connections` — список именованных подключений
- `driver` — встроенный или пользовательский адаптер

Полный список ключей и примеры смотрите в `config/sms-gateway.php`.

### Использование facade

```php
use SmsGateway\Laravel\Facades\SmsGateway;

SmsGateway::send('+992937123456', 'Ваш код: 1234');

SmsGateway::provider('payom')->send('+992937123456', 'Привет');

SmsGateway::send(
    to: '+992937123456',
    text: 'Привет',
    from: 'ACME',
    metadata: ['client_ref' => 'r-1'],
);
```

### Constructor injection

`Sender` также привязывается в container, поэтому его можно напрямую инжектить:

```php
use SmsGateway\Sender;

final class SendOtpCommand
{
    public function __construct(
        private readonly Sender $sms,
    ) {}

    public function handle(string $phone, string $code): void
    {
        $this->sms->send($phone, "Ваш код: {$code}");
    }
}
```

### Пользовательский driver

Собственный драйвер можно зарегистрировать через `extend()`:

```php
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\Laravel\Facades\SmsGateway;

SmsGateway::extend('custom', function ($app, array $config, string $name): SendsSmsInterface {
    return new CustomSmsProvider(...);
});
```

## Встроенные провайдеры

| Driver | Возможности | Кратко | Документация |
| --- | --- | --- | --- |
| `payom` | send | status lookup не поддерживается | [`docs/ru/providers/payom.md`](docs/ru/providers/payom.md) |
| `osonsms` | send + status | `messageId` имеет composite-формат и должен считаться opaque | [`docs/ru/providers/osonsms.md`](docs/ru/providers/osonsms.md) |
| `aliftech` | send + status | поддерживает metadata для `sms_type`, `priority`, `scheduled_at` и других полей | [`docs/ru/providers/aliftech.md`](docs/ru/providers/aliftech.md) |

## Свой провайдер

Библиотека построена contract-first. Чтобы добавить нового провайдера:

- реализуйте `SendsSmsInterface`, если нужен только send
- реализуйте `TracksSmsStatusInterface`, если нужен только status tracking
- реализуйте `SmsProviderInterface`, если провайдер поддерживает оба сценария

Главное правило: не отдавайте наружу vendor-specific исключения. Преобразуйте их в `ProviderException` или `MessageNotFoundException`, а нативные статусы приводите к `MessageStatus`.

## Разработка

```bash
composer install
composer test
```

## Лицензия

MIT
