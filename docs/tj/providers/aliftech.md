# Aliftech

[Тоҷикӣ](aliftech.md) | [Русский](../../ru/providers/aliftech.md) | [English](../../en/providers/aliftech.md)

[Бозгашт ба README](../../../README.md)

Ҳуҷҷати кӯтоҳи адаптери `aliftech` барои `sqdev/sms-gateway`.

## Хулоса

- class: `SmsGateway\Providers\Aliftech\AliftechProvider`
- provider name: `aliftech`
- қобилият: `send` ва `status`
- base URI-и пешфарз: `https://sms2.aliftech.net`
- fallback base URI: `https://smsgate.tj`
- auth: `X-Api-Key`

Адаптер `SmsProviderInterface`-ро амалӣ мекунад ва status tracking-ро дастгирӣ менамояд.

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

$result = $sender->send('+992900900900', 'Рамзи шумо: 12345');
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

## Metadata-и дастгиришаванда

| Key | Маъно |
| --- | --- |
| `sms_type` | `SmsType` ё integer `1/2/3` |
| `priority` | `SmsPriority` ё integer `0/1/2` |
| `scheduled_at` | `DateTimeInterface` ё ISO-8601 string |
| `expires_in` | миқдори сонияҳо |
| `label` | label барои grouping |
| `client_message_id` | ID-и берунии шумо |

Адаптер `sms_type` ва `priority`-ро бо enum-ҳои худ низ дастгирӣ мекунад.

## Status tracking

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;

$provider = $sender->provider();

if ($provider instanceof TracksSmsStatusInterface) {
    $status = $provider->getStatus($result->messageId);
}
```

Mapping-и асосӣ:

| Aliftech | MessageStatus |
| --- | --- |
| `Enroute`, `Accepted` | `Sent` |
| `Delivered` | `Delivered` |
| `Expired` | `Expired` |
| `Deleted`, `Rejected` | `Rejected` |
| `Undeliverable` | `Undelivered` |
| `None`, `Unknown` | `Unknown` |

## Рафтори муҳим

- рақами телефон пеш аз request normalize мешавад
- барои auth header-и `X-Api-Key` истифода мешавад, на `Bearer`
- агар `SenderAddress` дода нашавад, адаптер `InvalidMessageException` мепартояд
- `MessageError` дар ҷавоби send ва `CommandStatus != OK` дар status ба `ProviderException` табдил меёбанд
- bulk API-и Aliftech дар shared contract-и ҳозира нест
