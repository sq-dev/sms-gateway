# OsonSMS

[Тоҷикӣ](../../tj/providers/osonsms.md) | [Русский](osonsms.md) | [English](../../en/providers/osonsms.md)

[Назад к README](../../../README.ru.md)

Краткая документация по адаптеру `osonsms` для `sqdev/sms-gateway`.

## Кратко

- class: `SmsGateway\Providers\OsonSms\OsonSmsProvider`
- provider name: `osonsms`
- возможности: `send` и `status`
- base URI по умолчанию: `https://api.osonsms.com`
- auth: `Bearer` token + `login`

Адаптер реализует `SmsProviderInterface`, поэтому поддерживает и отправку, и `getStatus()`.

## Plain PHP

```php
use SmsGateway\Providers\OsonSms\OsonSmsProvider;
use SmsGateway\Sender;

$sender = new Sender(new OsonSmsProvider(
    token: $_ENV['OSONSMS_TOKEN'],
    login: $_ENV['OSONSMS_LOGIN'],
    defaultSenderName: 'MYBRAND',
));

$result = $sender->send('+992900000000', 'Привет');
```

## Laravel config

```php
'osonsms' => [
    'driver' => 'osonsms',
    'token' => env('OSONSMS_TOKEN'),
    'login' => env('OSONSMS_LOGIN'),
    'default_sender_name' => env('OSONSMS_DEFAULT_SENDER'),
    'base_uri' => env('OSONSMS_BASE_URI'),
],
```

## Важно: composite `messageId`

Для status lookup OsonSMS требует два значения:

- `txn_id` со стороны клиента
- `msg_id` со стороны сервера

Поэтому адаптер кодирует их в один `messageId`:

```text
{txn_id}|{msg_id}
```

Считайте это значение opaque. Не разбирайте его вручную и не собирайте сами. Для `getStatus()` достаточно передать тот `messageId`, который вернул `send()`.

## Поддерживаемая metadata

| Key | Значение |
| --- | --- |
| `txn_id` | ваш собственный idempotency key |
| `channel` | дополнительный канал, например `telegram` |
| `is_confidential` | если `true`, текст сообщения не сохраняется у провайдера |

Если вы задаете `txn_id` вручную, это должен быть непустой string без символа `|`.

## Status tracking

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;

$provider = $sender->provider();

if ($provider instanceof TracksSmsStatusInterface) {
    $status = $provider->getStatus($result->messageId);
}
```

Основной mapping статусов:

| OsonSMS | MessageStatus |
| --- | --- |
| `ENROUTE` | `Queued` |
| `ACCEPTED` | `Sent` |
| `DELIVERED` | `Delivered` |
| `EXPIRED` | `Expired` |
| `UNDELIVERABLE` | `Undelivered` |
| `DELETED`, `REJECTED` | `Rejected` |
| остальные значения | `Unknown` |

## Важное поведение

- номер очищается от `+`, пробелов, `-` и скобок; адаптер не добавляет country code автоматически
- запросы send и status используют `GET`
- при timeout повтор с тем же `txn_id` может быть идемпотентным
- HTTP, auth и transport ошибки преобразуются в `ProviderException`
