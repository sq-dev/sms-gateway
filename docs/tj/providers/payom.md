# Payom

[Тоҷикӣ](payom.md) | [Русский](../../ru/providers/payom.md) | [English](../../en/providers/payom.md)

[Бозгашт ба README](../../../README.md)

Ҳуҷҷати кӯтоҳи адаптери `payom` барои `sq-dev/sms-gateway`.

## Хулоса

- class: `SmsGateway\Providers\Payom\PayomSmsProvider`
- provider name: `payom`
- қобилият: танҳо `send`
- base URI-и пешфарз: `https://gateway.payom.tj`
- auth: `Bearer` token

Payom ҳоло `TracksSmsStatusInterface`-ро амалӣ намекунад, зеро дар API-и оммавии ҳуҷҷатшуда endpoint барои status lookup нест.

## Plain PHP

```php
use SmsGateway\Providers\Payom\PayomSmsProvider;
use SmsGateway\Sender;

$sender = new Sender(new PayomSmsProvider(
    token: $_ENV['PAYOM_JWT_TOKEN'],
    defaultSenderName: 'payom.tj',
));

$result = $sender->send('+992937123456', 'Салом');
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

## Чӣ ба API меравад

Адаптер `SmsMessage`-ро ба request-и Payom чунин табдил медиҳад:

| SmsGateway | Payom |
| --- | --- |
| `to` | `telephone` |
| `text` | `text` |
| `from` ё `defaultSenderName` | `senderName` |
| ҳамеша | `type = "SMS"` |

Дар ин адаптер `metadata` ҳоло ба request илова намешавад.

## Маҳдудиятҳо ва рафтор

- `senderName` ҳатмӣ аст. Агар дар `SmsMessage::$from` набошад, бояд `defaultSenderName` дода шавад.
- агар `senderName` дар ҳисоби Payom сабт нашуда бошад, API request-ро рад мекунад.
- хатогиҳои transport ва HTTP ба `ProviderException` табдил меёбанд.
- агар ҷавоб `id` надошта бошад, адаптер exception мепартояд.

## Status

Payom ҳоло status lookup-и умумиро намедиҳад. Бинобар ин:

- `SendResult` доред
- `messageId` доред
- аммо `getStatus()` вуҷуд надорад

Агар Payom баъдтар endpoint барои status пешниҳод кунад, адаптер метавонад бе шикастани API-и мавҷуда tracking-ро илова кунад.
