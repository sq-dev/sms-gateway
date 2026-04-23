# Payom

[Тоҷикӣ](../../tj/providers/payom.md) | [Русский](../../ru/providers/payom.md) | [English](payom.md)

[Back to README](../../../README.en.md)

Short reference for the `payom` adapter in `sqdev/sms-gateway`.

## Summary

- class: `SmsGateway\Providers\Payom\PayomSmsProvider`
- provider name: `payom`
- capability: `send` only
- default base URI: `https://gateway.payom.tj`
- auth: `Bearer` token

Payom does not currently implement `TracksSmsStatusInterface` because the documented public API does not expose a status lookup endpoint.

## Plain PHP

```php
use SmsGateway\Providers\Payom\PayomSmsProvider;
use SmsGateway\Sender;

$sender = new Sender(new PayomSmsProvider(
    token: $_ENV['PAYOM_JWT_TOKEN'],
    defaultSenderName: 'payom.tj',
));

$result = $sender->send('+992937123456', 'Hello');
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

## What gets sent to the API

The adapter maps `SmsMessage` into the Payom request like this:

| SmsGateway | Payom |
| --- | --- |
| `to` | `telephone` |
| `text` | `text` |
| `from` or `defaultSenderName` | `senderName` |
| always | `type = "SMS"` |

In the current adapter, `metadata` is not forwarded to Payom.

## Constraints and behavior

- `senderName` is required. If it is missing from `SmsMessage::$from`, you must provide `defaultSenderName`.
- if the sender is not registered in the Payom account, the API rejects the request.
- transport and HTTP failures are translated into `ProviderException`.
- if the response is missing `id`, the adapter throws an exception.

## Status support

Payom does not currently expose a shared status lookup flow. That means:

- you get a `SendResult`
- you get a `messageId`
- but there is no `getStatus()`

If Payom later documents a status endpoint, the adapter can add tracking support without breaking the current API.
