# sqdev/sms-gateway

[Тоҷикӣ](README.md) | [Русский](README.ru.md) | [English](README.en.md)

Китобхонаи `sqdev/sms-gateway` барои PHP интерфейси ягонаи фиристодани SMS медиҳад. Шумо метавонед як API-и устуворро барои чанд провайдер истифода баред, натиҷаҳои фиристоданро дар шакли ягона гиред ва дар Laravel ҳамон шартномаҳоро тавассути facade ва manager истифода баред.

## Чӣ медиҳад

- `Sender` барои фиристодани SMS бо API-и содда.
- `SendResult` ва `StatusResult` барои натиҷаҳои ягона байни провайдерҳо.
- `MessageStatus` барои ҳолатҳои умумӣ, мисли `Queued`, `Sent`, `Delivered`.
- интегратсияи тайёри Laravel бо `SmsGateway` facade, config ва connection manager.
- имкони илова кардани провайдери худатон бе тағйир додани коди истеъмолкунанда.

## Насб

```bash
composer require sqdev/sms-gateway
```

Китобхона ба PSR-18 HTTP client такя мекунад. Агар дар лоиҳа аллакай Guzzle, Symfony HTTP Client ё ягон PSR-18 client мавҷуд бошад, `php-http/discovery` онро худкор пайдо мекунад. Агар надошта бошед, якеашро насб кунед:

```bash
composer require guzzlehttp/guzzle
# ё
composer require symfony/http-client
```

## Талабот

- PHP `^8.1`
- Composer
- як PSR-18 HTTP client

## Оғози зуд

Мисоли оддӣ бо яке аз провайдерҳои дарунсохт:

```php
use SmsGateway\Sender;
use SmsGateway\Providers\Payom\PayomSmsProvider;

$sender = new Sender(new PayomSmsProvider(
    token: $_ENV['PAYOM_JWT_TOKEN'],
    defaultSenderName: 'payom.tj',
));

$result = $sender->send('+992937123456', 'Рамзи тасдиқи шумо: 1234');
```

Натиҷа ҳамеша `SendResult` аст:

```php
$result->messageId;    // ID аз тарафи провайдер
$result->status;       // MessageStatus
$result->providerName; // масалан "payom"
$result->raw;          // ҷавоби аслии провайдер барои debug/log
```

## API-и асосӣ

`Sender` қабати тунук болои `SendsSmsInterface` аст. Барои аксари ҳолатҳо ҳамин се метод кофӣ мебошад:

```php
$sender->send(
    to: '+992937123456',
    text: 'Салом',
    from: 'ACME',
    metadata: ['client_ref' => 'r-1'],
);

$sender->sendMessage($smsMessage);

$provider = $sender->provider();
```

Агар провайдер status tracking-ро дастгирӣ кунад, метавонед онро бо `TracksSmsStatusInterface` санҷед:

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;

$provider = $sender->provider();

if ($provider instanceof TracksSmsStatusInterface) {
    $status = $provider->getStatus($result->messageId);

    if ($status->status->isFinal()) {
        // polling-ро қатъ кунед
    }
}
```

## Модели ягонаи натиҷа ва хатогиҳо

Ҳамаи провайдерҳо ба ҳамон DTO ва enum табдил дода мешаванд:

- `SendResult` барои натиҷаи фиристодан
- `StatusResult` барои санҷиши ҳолат
- `MessageStatus` барои ҳолатҳои умумӣ
- `SmsGatewayException` барои base contract-и ҳамаи exception-ҳо

Муҳимтарин exception-ҳо:

- `InvalidMessageException` барои input ё metadata-и нодуруст
- `ProviderException` барои хатои remote API, auth, transport ва монанди ин
- `MessageNotFoundException` барои status lookup бо `messageId` нодуруст

## Laravel

Китобхона барои Laravel 10, 11 ва 12 интегратсияи тайёр дорад. Service provider ва facade худкор аз `composer.json` сабт мешаванд.

### Publish кардани config

```bash
php artisan vendor:publish --tag=sms-gateway-config
```

Баъд `config/sms-gateway.php` пайдо мешавад. Шакли умумии config чунин аст:

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

Дар ин ҷо:

- `default` connection-и асосӣ мебошад
- `connections` рӯйхати ҳамаи connection-ҳои номдор аст
- `driver` адаптери истифодашавандаро интихоб мекунад

Барои ҳамаи калидҳои дастгиришаванда ва мисолҳои пурра ба `config/sms-gateway.php` нигаред.

### Истифодаи facade

```php
use SmsGateway\Laravel\Facades\SmsGateway;

SmsGateway::send('+992937123456', 'Рамзи шумо: 1234');

SmsGateway::provider('payom')->send('+992937123456', 'Салом');

SmsGateway::send(
    to: '+992937123456',
    text: 'Салом',
    from: 'ACME',
    metadata: ['client_ref' => 'r-1'],
);
```

### Constructor injection

`Sender` ба container низ bind мешавад, бинобар ин метавонед онро мустақим inject кунед:

```php
use SmsGateway\Sender;

final class SendOtpCommand
{
    public function __construct(
        private readonly Sender $sms,
    ) {}

    public function handle(string $phone, string $code): void
    {
        $this->sms->send($phone, "Рамзи шумо: {$code}");
    }
}
```

### Custom driver

Агар провайдери худ дошта бошед, онро бо `extend()` сабт карда метавонед:

```php
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\Laravel\Facades\SmsGateway;

SmsGateway::extend('custom', function ($app, array $config, string $name): SendsSmsInterface {
    return new CustomSmsProvider(...);
});
```

## Провайдерҳои дарунсохт

| Driver | Қобилият | Хулоса | Ҳуҷҷат |
| --- | --- | --- | --- |
| `payom` | send | status lookup надорад | [`docs/tj/providers/payom.md`](docs/tj/providers/payom.md) |
| `osonsms` | send + status | `messageId` шакли composite дорад ва бояд opaque ҳисобида шавад | [`docs/tj/providers/osonsms.md`](docs/tj/providers/osonsms.md) |
| `aliftech` | send + status | metadata барои `sms_type`, `priority`, `scheduled_at` ва ғайра дорад | [`docs/tj/providers/aliftech.md`](docs/tj/providers/aliftech.md) |

## Навиштани провайдери худ

Китобхона contract-first сохта шудааст. Агар провайдери нав илова кардан хоҳед:

- `SendsSmsInterface`-ро амалӣ кунед, агар танҳо фиристодан дошта бошад
- `TracksSmsStatusInterface`-ро амалӣ кунед, агар танҳо tracking дошта бошад
- `SmsProviderInterface`-ро амалӣ кунед, агар ҳарду қобилиятро диҳад

Қоидаи асосӣ ин аст, ки exception-и провайдерро берун набароред; онро ба `ProviderException` ё `MessageNotFoundException` табдил диҳед ва ҳама status-ҳоро ба `MessageStatus` map намоед.

## Рушд

```bash
composer install
composer test
```

## Иҷозатнома

MIT
