# OsonSMS

[Тоҷикӣ](osonsms.md) | [Русский](../../ru/providers/osonsms.md) | [English](../../en/providers/osonsms.md)

[Бозгашт ба README](../../../README.md)

Ҳуҷҷати кӯтоҳи адаптери `osonsms` барои `sqdev/sms-gateway`.

## Хулоса

- class: `SmsGateway\Providers\OsonSms\OsonSmsProvider`
- provider name: `osonsms`
- қобилият: `send` ва `status`
- base URI-и пешфарз: `https://api.osonsms.com`
- auth: `Bearer` token + `login`

Адаптер `SmsProviderInterface`-ро амалӣ мекунад, бинобар ин ҳам фиристодан ва ҳам `getStatus()`-ро дастгирӣ менамояд.

## Plain PHP

```php
use SmsGateway\Providers\OsonSms\OsonSmsProvider;
use SmsGateway\Sender;

$sender = new Sender(new OsonSmsProvider(
    token: $_ENV['OSONSMS_TOKEN'],
    login: $_ENV['OSONSMS_LOGIN'],
    defaultSenderName: 'MYBRAND',
));

$result = $sender->send('+992900000000', 'Салом');
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

## Муҳим: `messageId`-и composite

OsonSMS барои status lookup ду арзиш мехоҳад:

- `txn_id` аз тарафи client
- `msg_id` аз тарафи сервер

Барои ҳамин адаптер онҳоро ба як `messageId` муттаҳид мекунад:

```text
{txn_id}|{msg_id}
```

Ин арзишро ҳамчун opaque нигоҳ доред. Онро parse накунед ва худатон насозед. Барои `getStatus()` ҳамон `messageId`, ки аз `send()` гирифтаед, кофист.

## Metadata-и дастгиришаванда

| Key | Маъно |
| --- | --- |
| `txn_id` | idempotency key-и худатон |
| `channel` | channel-и иловагӣ, мисли `telegram` |
| `is_confidential` | агар `true` бошад, матн дар базаи провайдер нигоҳ дошта намешавад |

Агар `txn_id`-ро худатон диҳед, он бояд string-и холӣ набошад ва аломати `|` надошта бошад.

## Status tracking

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;

$provider = $sender->provider();

if ($provider instanceof TracksSmsStatusInterface) {
    $status = $provider->getStatus($result->messageId);
}
```

Mapping-и асосии status:

| OsonSMS | MessageStatus |
| --- | --- |
| `ENROUTE` | `Queued` |
| `ACCEPTED` | `Sent` |
| `DELIVERED` | `Delivered` |
| `EXPIRED` | `Expired` |
| `UNDELIVERABLE` | `Undelivered` |
| `DELETED`, `REJECTED` | `Rejected` |
| дигар ҳолатҳо | `Unknown` |

## Рафтори муҳим

- рақами телефон бо гирифтани `+`, фосила, `-` ва қавсҳо тоза карда мешавад; адаптер country code-ро худкор илова намекунад
- request-ҳои send ва status ҳарду `GET` мебошанд
- ҳангоми timeout, истифодаи ҳамон `txn_id` барои retry idempotent буда метавонад
- хатогиҳои HTTP, auth ва transport ба `ProviderException` табдил меёбанд
