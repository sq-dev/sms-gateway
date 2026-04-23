# Payom

[Тоҷикӣ](../../tj/providers/payom.md) | [Русский](payom.md) | [English](../../en/providers/payom.md)

[Назад к README](../../../README.ru.md)

Краткая документация по адаптеру `payom` для `sq-dev/sms-gateway`.

## Кратко

- class: `SmsGateway\Providers\Payom\PayomSmsProvider`
- provider name: `payom`
- возможности: только `send`
- base URI по умолчанию: `https://gateway.payom.tj`
- auth: `Bearer` token

Payom пока не реализует `TracksSmsStatusInterface`, потому что в публично документированном API нет endpoint для status lookup.

## Plain PHP

```php
use SmsGateway\Providers\Payom\PayomSmsProvider;
use SmsGateway\Sender;

$sender = new Sender(new PayomSmsProvider(
    token: $_ENV['PAYOM_JWT_TOKEN'],
    defaultSenderName: 'payom.tj',
));

$result = $sender->send('+992937123456', 'Привет');
```

## Laravel config

```php
'payom' => [
    'driver' => 'payom',
    'token' => env('PAYOM_JWT_TOKEN'),
    'default_sender_name' => env('PAYOM_DEFAULT_SENDER'),
    'base_uri' => env('PAYOM_BASE_URI'),
],
```

## Что уходит в API

Адаптер преобразует `SmsMessage` в запрос Payom так:

| SmsGateway | Payom |
| --- | --- |
| `to` | `telephone` |
| `text` | `text` |
| `from` или `defaultSenderName` | `senderName` |
| всегда | `type = "SMS"` |

В текущей реализации `metadata` в запрос Payom не пробрасывается.

## Ограничения и поведение

- `senderName` обязателен. Если его нет в `SmsMessage::$from`, нужно задать `defaultSenderName`.
- если sender не зарегистрирован в кабинете Payom, API отклонит запрос.
- transport и HTTP ошибки преобразуются в `ProviderException`.
- если в ответе нет `id`, адаптер выбросит exception.

## Status

Сейчас Payom не предоставляет общего status lookup. Поэтому:

- вы получаете `SendResult`
- вы получаете `messageId`
- но `getStatus()` недоступен

Если Payom позже опубликует endpoint для статусов, адаптер сможет добавить tracking без поломки существующего API.
