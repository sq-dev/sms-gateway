# Aliftech

[Тоҷикӣ](../../tj/providers/aliftech.md) | [Русский](aliftech.md) | [English](../../en/providers/aliftech.md)

[Назад к README](../../../README.ru.md)

Краткая документация по адаптеру `aliftech` для `sq-dev/sms-gateway`.

## Кратко

- class: `SmsGateway\Providers\Aliftech\AliftechProvider`
- provider name: `aliftech`
- возможности: `send` и `status`
- base URI по умолчанию: `https://sms2.aliftech.net`
- fallback base URI: `https://smsgate.tj`
- auth: `X-Api-Key`

Адаптер реализует `SmsProviderInterface` и поддерживает status tracking.

## Plain PHP

```php
use SmsGateway\Providers\Aliftech\AliftechProvider;
use SmsGateway\Providers\Aliftech\SmsType;
use SmsGateway\Sender;

$sender = new Sender(new AliftechProvider(
    apiKey: $_ENV['ALIFTECH_API_KEY'],
    defaultSenderName: 'AlifBank',
    defaultSmsType: SmsType::Otp,
));

$result = $sender->send('+992900900900', 'Ваш код: 12345');
```

## Laravel config

```php
'aliftech' => [
    'driver' => 'aliftech',
    'api_key' => env('ALIFTECH_API_KEY'),
    'default_sender_name' => env('ALIFTECH_DEFAULT_SENDER'),
    'default_sms_type' => env('ALIFTECH_DEFAULT_SMS_TYPE'),
    'base_uri' => env('ALIFTECH_BASE_URI'),
],
```

## Поддерживаемая metadata

| Key | Значение |
| --- | --- |
| `sms_type` | `SmsType` или integer `1/2/3` |
| `priority` | `SmsPriority` или integer `0/1/2` |
| `scheduled_at` | `DateTimeInterface` или ISO-8601 string |
| `expires_in` | количество секунд |
| `label` | label для группировки |
| `client_message_id` | ваш внешний ID |

Адаптер также поддерживает enum-ы `SmsType` и `SmsPriority`.

## Status tracking

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;

$provider = $sender->provider();

if ($provider instanceof TracksSmsStatusInterface) {
    $status = $provider->getStatus($result->messageId);
}
```

Основной mapping:

| Aliftech | MessageStatus |
| --- | --- |
| `Enroute`, `Accepted` | `Sent` |
| `Delivered` | `Delivered` |
| `Expired` | `Expired` |
| `Deleted`, `Rejected` | `Rejected` |
| `Undeliverable` | `Undelivered` |
| `None`, `Unknown` | `Unknown` |

## Важное поведение

- номер телефона нормализуется перед отправкой
- для auth используется заголовок `X-Api-Key`, а не `Bearer`
- если нет `SenderAddress`, адаптер выбросит `InvalidMessageException`
- `MessageError` в send-ответе и `CommandStatus != OK` в status-ответе превращаются в `ProviderException`
- bulk API Aliftech не входит в текущий shared contract
