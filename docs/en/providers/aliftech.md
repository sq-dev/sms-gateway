# Aliftech

[Тоҷикӣ](../../tj/providers/aliftech.md) | [Русский](../../ru/providers/aliftech.md) | [English](aliftech.md)

[Back to README](../../../README.en.md)

Short reference for the `aliftech` adapter in `sq-dev/sms-gateway`.

## Summary

- class: `SmsGateway\Providers\Aliftech\AliftechProvider`
- provider name: `aliftech`
- capability: `send` and `status`
- default base URI: `https://sms2.aliftech.net`
- fallback base URI: `https://smsgate.tj`
- auth: `X-Api-Key`

The adapter implements `SmsProviderInterface` and supports status tracking.

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

$result = $sender->send('+992900900900', 'Your code is 12345');
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

## Supported metadata

| Key | Meaning |
| --- | --- |
| `sms_type` | `SmsType` or integer `1/2/3` |
| `priority` | `SmsPriority` or integer `0/1/2` |
| `scheduled_at` | `DateTimeInterface` or ISO-8601 string |
| `expires_in` | number of seconds |
| `label` | label for grouping |
| `client_message_id` | your external identifier |

The adapter also supports the `SmsType` and `SmsPriority` enums directly.

## Status tracking

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;

$provider = $sender->provider();

if ($provider instanceof TracksSmsStatusInterface) {
    $status = $provider->getStatus($result->messageId);
}
```

Core mapping:

| Aliftech | MessageStatus |
| --- | --- |
| `Enroute`, `Accepted` | `Sent` |
| `Delivered` | `Delivered` |
| `Expired` | `Expired` |
| `Deleted`, `Rejected` | `Rejected` |
| `Undeliverable` | `Undelivered` |
| `None`, `Unknown` | `Unknown` |

## Important behavior

- phone numbers are normalized before the request is sent
- authentication uses `X-Api-Key`, not `Bearer`
- if `SenderAddress` is missing, the adapter throws `InvalidMessageException`
- `MessageError` in the send response and `CommandStatus != OK` in the status response become `ProviderException`
- the Aliftech bulk API is outside the current shared contract
