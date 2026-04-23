# sqdev/sms-gateway

Provider-agnostic SMS gateway abstractions for PHP with a unified send API and
a unified delivery-status model across multiple SMS providers.

This package ships:

- A thin **`Sender` facade** so call sites read like `$sender->send('+992…', 'Hi')`.
- **Shared contracts and a normalized domain model** (DTOs, status enum,
  exceptions) so swapping providers never requires rewriting call sites.
- **Built-in provider adapters** — currently [Payom.tj](#payomtj-provider),
  [OsonSMS](#osonsms-provider), and [SMSGate](#smsgate-provider) — that come
  fully wired, with no manual HTTP-client setup required.

## Installation

```bash
composer require sqdev/sms-gateway
```

The library relies on any PSR-18 HTTP client you already have
(Guzzle, Symfony HTTP Client, php-http/curl-client, …). `php-http/discovery`
picks it up automatically, so in most projects there is nothing else to install.
If you have none, add one alongside the library:

```bash
composer require guzzlehttp/guzzle
# or
composer require symfony/http-client
```

## Requirements

- PHP `^8.1`
- Composer
- A PSR-18 HTTP client installed anywhere in your project (auto-discovered).

## Quick start

Three lines from zero to a sent SMS:

```php
use SmsGateway\Sender;
use SmsGateway\Providers\Payom\PayomSmsProvider;

$sender = new Sender(new PayomSmsProvider(
    token: $_ENV['PAYOM_JWT_TOKEN'],
    defaultSenderName: 'payom.tj',
));

$result = $sender->send('+992937123456', 'Your verification code is 1234.');
```

- Pick a provider by instantiating it with its credentials.
- Wrap it in `Sender`.
- Call `$sender->send($to, $text)`.

No HTTP client wiring, no PSR-17 factories, no `SmsMessage` DTO — unless you
want them (see [Advanced usage](#advanced-usage) below).

`$result` is a normalized `SendResult`:

```php
$result->messageId;    // provider-assigned id, use it for status lookups later
$result->status;       // MessageStatus enum (queued, sent, delivered, …)
$result->providerName; // e.g. 'payom'
$result->raw;          // raw provider response for logging/debug
```

## What the `Sender` does

The `Sender` is intentionally tiny. Its whole job is to let consumer code
dispatch SMS without constructing DTOs by hand:

```php
// Positional send – optional sender id and metadata.
$sender->send(
    to: '+992937123456',
    text: 'Hi',
    from: 'ACME',                     // optional, overrides provider default
    metadata: ['client_ref' => 'r-1'], // optional, provider-agnostic hints
);

// When you already have an SmsMessage DTO.
$sender->sendMessage($smsMessage);

// Reach the underlying provider for capabilities beyond send.
$sender->provider(); // SendsSmsInterface
```

Because `Sender` wraps a `SendsSmsInterface`, you can point it at any built-in
provider or a custom one you wrote yourself (see
[Authoring a custom provider](#authoring-a-custom-provider)).

## Built-in providers

### Payom.tj provider

Adapter for the Payom.tj gateway at `https://gateway.payom.tj`. Implements
**`SendsSmsInterface` only** because the public Payom API (v1.0, 12.07.2025)
documents a single outbound endpoint (`POST /api/message`) and does not expose
a status-lookup endpoint. If Payom publishes one, the adapter can start
implementing `TracksSmsStatusInterface` without breaking consumers.

#### Construction

```php
use SmsGateway\Providers\Payom\PayomSmsProvider;

$payom = new PayomSmsProvider(
    token: $_ENV['PAYOM_JWT_TOKEN'],
    defaultSenderName: 'payom.tj', // optional; can be overridden per message
);
```

That's it — the HTTP stack is auto-discovered. Pass `httpClient`,
`requestFactory`, `streamFactory`, or `baseUri` as named arguments only if you
need to customize them (DI containers, testing, staging environments).

#### Field mapping

| `SmsMessage` field      | Payom API field |
| ----------------------- | --------------- |
| `to`                    | `telephone`     |
| `text`                  | `text`          |
| `from` / default sender | `senderName`    |
| (always `"SMS"`)        | `type`          |

`SmsMessage::$metadata` is currently not forwarded — Payom documents no extra
input fields beyond the four above. If that changes, the adapter will read
well-known metadata keys in a follow-up release.

#### Delivery-status mapping

Payom's documentation only confirms `ACCEPTED` explicitly; the remaining
values follow industry conventions and are best-effort guesses that will be
refined once Payom publishes a full status taxonomy.

| Payom `deliveryStatus`           | `MessageStatus` |
| -------------------------------- | --------------- |
| `ACCEPTED`, `QUEUED`, `PENDING`, `NEW` | `Queued`   |
| `SENT`, `IN_PROGRESS`            | `Sent`          |
| `DELIVERED`                      | `Delivered`     |
| `REJECTED`                       | `Rejected`      |
| `UNDELIVERED`, `NOT_DELIVERED`   | `Undelivered`   |
| `FAILED`, `ERROR`                | `Failed`        |
| `EXPIRED`                        | `Expired`       |
| anything else                    | `Unknown`       |

#### Errors

Every failure is translated into a library exception — vendor-specific
exceptions never leak out.

| Situation                       | Library exception        | `getProviderCode()` |
| ------------------------------- | ------------------------ | ------------------- |
| Missing sender name             | `InvalidMessageException`| –                   |
| Transport failure (DNS/TLS/…)   | `ProviderException`      | `null`              |
| HTTP 401 / 403 / 422 / 500 / …  | `ProviderException`      | HTTP status code    |
| Response missing `id`           | `ProviderException`      | HTTP status code    |

```php
use SmsGateway\Exception\ProviderException;

try {
    $sender->send('+992937123456', 'Hello');
} catch (ProviderException $e) {
    match ($e->getProviderCode()) {
        '401'   => $logger->error('Payom token expired.'),
        '422'   => $logger->warning('Payom rejected the payload: ' . $e->getMessage()),
        default => $logger->error('Payom failure', ['exception' => $e]),
    };
}
```

#### Operational notes

- Payom enforces length limits (up to 153 chars for Latin, up to 67 for
  Unicode) with automatic server-side segmentation on overflow.
- `senderName` must be pre-registered in your Payom account. Unregistered
  senders are rejected with HTTP 422.
- `PayomSmsProvider::DEFAULT_BASE_URI` points at production. Override via the
  `baseUri` constructor argument for staging/testing environments.

### OsonSMS provider

Adapter for the OsonSMS gateway at `https://api.osonsms.com`. Implements the
full `SmsProviderInterface` (send **and** status tracking), based on the
OsonSMS Interaction Protocol v2.0.2 (08.02.2026).

#### Construction

```php
use SmsGateway\Providers\OsonSms\OsonSmsProvider;
use SmsGateway\Sender;

$oson = new OsonSmsProvider(
    token: $_ENV['OSONSMS_TOKEN'],
    login: $_ENV['OSONSMS_LOGIN'],
    defaultSenderName: 'MYBRAND', // optional; can be overridden per message
);

$sender = new Sender($oson);
$result = $sender->send('+992900000000', 'Hello');
```

The HTTP stack is auto-discovered; pass `httpClient`/`requestFactory`/
`streamFactory`/`baseUri` as named args only when you need to customize them.

#### Field mapping

OsonSMS uses `GET` requests with query parameters. The adapter translates the
shared DTO + metadata into the vendor query string:

| Source                                      | OsonSMS query param  |
| ------------------------------------------- | -------------------- |
| `SmsMessage::$to` (normalized — see below)  | `phone_number`       |
| `SmsMessage::$text`                         | `msg`                |
| `SmsMessage::$from` / default sender        | `from`               |
| Provider constructor `login`                | `login`              |
| `SmsMessage::$metadata['txn_id']` or auto   | `txn_id`             |
| `SmsMessage::$metadata['channel']`          | `channel` (optional) |
| `SmsMessage::$metadata['is_confidential']`  | `is_confidential` as `"true"`/`"false"` |

Phone numbers are normalized before the request: leading `+`, spaces,
hyphens, and parentheses are stripped so `'+992 90 000-00-00'`,
`'+992900000000'`, and `'992900000000'` all become `992900000000`. Malformed
numbers pass through so the server can reject them with the most precise
error code.

#### The `txn_id` and the opaque message id

OsonSMS requires a client-generated `txn_id` (idempotency key) on every send
and a separate server-assigned `msg_id`. Status lookups need **both**.

To fit this into the library's single-string `messageId`, the adapter encodes
them as `"{txn_id}|{msg_id}"`. **Treat `SendResult::$messageId` as opaque** —
do not parse it or build it manually; the format may change. `getStatus()`
accepts the same string and decodes it internally.

If you need an idempotency key you can reproduce on retry, pass your own via
metadata:

```php
$sender->send(
    to: '+992900000000',
    text: 'Hello',
    metadata: ['txn_id' => 'order-2026-04-23-0001'],
);
```

A user-provided `txn_id` must be a non-empty string and must not contain `|`
(reserved as the internal separator). Those cases throw
`InvalidMessageException` before the request is dispatched.

#### Optional metadata keys

| Metadata key      | Effect                                                                              |
| ----------------- | ----------------------------------------------------------------------------------- |
| `txn_id`          | Use the supplied idempotency key instead of auto-generating one.                    |
| `channel`         | Deliver through a messenger bot instead of SMS (currently only `"telegram"`).       |
| `is_confidential` | When `true`, OsonSMS does not store the message text in its DB or cabinet.          |

Use the `OsonSmsProvider::METADATA_*` constants if you prefer typed keys.

#### Delivery-status mapping

Every state documented in the OsonSMS protocol is mapped to a normalized
`MessageStatus`:

| OsonSMS `status` | `MessageStatus` | Notes                                                   |
| ---------------- | --------------- | ------------------------------------------------------- |
| `ENROUTE`        | `Queued`        | Routed, waiting to dispatch                             |
| `ACCEPTED`       | `Sent`          | Dispatched, awaiting carrier DLR                        |
| `DELIVERED`      | `Delivered`     | Final – carrier confirmed delivery                      |
| `EXPIRED`        | `Expired`       | Final – TTL ran out                                     |
| `UNDELIVERABLE`  | `Undelivered`   | Final – invalid number / no memory / …                  |
| `DELETED`        | `Rejected`      | Final – message canceled (closest terminal state)       |
| `REJECTED`       | `Rejected`      | Final – mobile network rejected (filter/limit)          |
| `UNKNOWN`        | `Unknown`       | Provider could not determine state                      |
| anything else    | `Unknown`       | Defensive fallback for future OsonSMS status additions  |

#### Errors

All failures (HTTP 4xx / 5xx, transport errors, malformed responses) become
`ProviderException`. The adapter extracts the OsonSMS internal error code
from the `error.code` field and exposes it via `getProviderCode()` — these
codes (100–119) are far more specific than HTTP status alone:

| Situation                      | HTTP | `getProviderCode()` | Meaning                                |
| ------------------------------ | ---- | ------------------- | -------------------------------------- |
| Missing mandatory variable     | 422  | `100`               | Request missing `from`/`phone_number`/`msg`/`login`/`txn_id` |
| Inactive or non-existent user  | 401  | `105`               | Account blocked                        |
| Incorrect Authorization        | 422  | `106`               | Bad Bearer token                       |
| Incorrect sender               | 422  | `107`               | Sender id not registered for account   |
| Duplicate `txn_id`             | 409  | `108`               | Idempotent replay detected             |
| Unable to store message        | 500  | `109`               | Storage failure                        |
| Error while sending SMS        | 501  | `112`               | Dispatch failure                       |
| Unable to connect to SMSC      | 599  | `113`               | Upstream connectivity                  |
| Host not in whitelist          | 401  | `114`               | Caller IP not whitelisted              |
| Balance exhausted              | 402  | `119`               | Top up required                        |
| Transport failure (DNS/TLS/…)  | –    | `null`              | Original exception attached as `previous` |

```php
use SmsGateway\Exception\ProviderException;

try {
    $sender->send('+992900000000', 'Hello');
} catch (ProviderException $e) {
    match ($e->getProviderCode()) {
        '108'   => $logger->notice('OsonSMS idempotent replay', ['txn_id' => '…']),
        '119'   => $billing->flagLowBalance(),
        '106', '105', '114' => $logger->alert('OsonSMS credential/whitelist issue'),
        default => $logger->error('OsonSMS failure', ['exception' => $e]),
    };
}
```

#### Status tracking

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;
use SmsGateway\Enum\MessageStatus;

$result = $sender->send('+992900000000', 'Hello');

// $sender->provider() is typed as SendsSmsInterface; downcast to the full
// contract to unlock getStatus().
$provider = $sender->provider();
assert($provider instanceof TracksSmsStatusInterface);

$status = $provider->getStatus($result->messageId);

if ($status->status->isFinal()) {
    // stop polling
}
```

#### Operational notes

- OsonSMS allows only HTTPS and enforces a 20-second request/response
  timeout. On timeout, re-sending the same request **with the same `txn_id`**
  is idempotent – pass a stable `txn_id` via metadata to guarantee this.
- Send and status endpoints are both `GET` with query params; the message
  text is URL-encoded in the query string. Standard SMS lengths easily fit.
- `OsonSmsProvider::DEFAULT_BASE_URI` points at production
  (`https://api.osonsms.com`). Override via `baseUri` for staging.
- Bulk-send and balance-check endpoints from the OsonSMS protocol are
  out of scope for the shared contracts and are not exposed by this adapter.

### SMSGate provider

Adapter for the SMSGate HTTP API (Alif Tech). Implements the full
`SmsProviderInterface` (send **and** status tracking) using the documented
`POST /api/v1/sms` and `GET /api/v1/sms/{id}` endpoints.

#### Construction

```php
use SmsGateway\Providers\SmsGate\SmsGateProvider;
use SmsGateway\Providers\SmsGate\SmsType;
use SmsGateway\Sender;

$smsgate = new SmsGateProvider(
    apiKey: $_ENV['SMSGATE_API_KEY'],
    defaultSenderName: 'AlifBank',
    defaultSmsType: SmsType::Otp, // optional, defaults to Common
);

$sender = new Sender($smsgate);
$result = $sender->send('+992900900900', 'Ваш код подтверждения: 12345');
```

SMSGate authenticates with `X-Api-Key`, not a Bearer token. The adapter sets
that header automatically. By default it uses the documented primary endpoint
`https://sms2.aliftech.net`; switch to
`SmsGateProvider::FALLBACK_BASE_URI` if you want the documented reserve host.

#### Field mapping

SMSGate expects a JSON body. The adapter maps the shared DTO plus optional
metadata into the documented request payload:

| Source                                          | SMSGate body field |
| ----------------------------------------------- | ------------------ |
| `SmsMessage::$to` (normalized to digits only)   | `PhoneNumber`      |
| `SmsMessage::$text`                             | `Text`             |
| `SmsMessage::$from` / default sender            | `SenderAddress`    |
| provider default `SmsType` or metadata override | `SmsType`          |
| `metadata['priority']`                          | `Priority`         |
| `metadata['scheduled_at']`                      | `ScheduledAt`      |
| `metadata['expires_in']`                        | `ExpiresIn`        |
| `metadata['label']`                             | `SmsLabel`         |
| `metadata['client_message_id']`                 | `ClientMessageId`  |

Phone numbers are normalized before the request: `+`, spaces, hyphens, and
parentheses are stripped so `+992 90 090-09-00` becomes `992900900900`, which
matches the format expected by the API.

#### Provider-specific enums

SMSGate has documented integer enums for both message type and priority, so the
adapter ships type-safe PHP enums as sugar:

| PHP enum                                     | API field   | Values |
| -------------------------------------------- | ----------- | ------ |
| `SmsGateway\Providers\SmsGate\SmsType`       | `SmsType`   | `Common=1`, `Otp=2`, `Batch=3` |
| `SmsGateway\Providers\SmsGate\SmsPriority`   | `Priority`  | `Low=0`, `Normal=1`, `High=2` |

You can pass either the enum instance or the raw documented integer in
metadata. Example:

```php
use SmsGateway\Providers\SmsGate\SmsPriority;
use SmsGateway\Providers\SmsGate\SmsType;

$sender->send(
    to: '+992900900900',
    text: 'Ваш код подтверждения: 12345',
    metadata: [
        'sms_type' => SmsType::Otp,
        'priority' => SmsPriority::High,
        'client_message_id' => 'order-2026-04-23-001',
    ],
);
```

#### Scheduling and expiry

`scheduled_at` accepts either a `DateTimeInterface` or a non-empty ISO-8601
string. `DateTimeInterface` values are converted to UTC automatically, matching
the API contract:

```php
$sender->send(
    to: '+992900900900',
    text: 'Напоминание о платеже',
    metadata: [
        'scheduled_at' => new DateTimeImmutable('2026-04-23 12:00:00', new DateTimeZone('Asia/Dushanbe')),
        'expires_in' => 3600,
        'label' => 'billing-reminders',
    ],
);
```

#### Delivery-status mapping

SMSGate returns `MessageState` either as a string or as its numeric code. Both
forms are accepted and normalized into `MessageStatus`:

| SMSGate `MessageState` | Code | `MessageStatus` |
| ---------------------- | ---- | --------------- |
| `None`                 | `0`  | `Unknown`       |
| `Enroute`              | `1`  | `Sent`          |
| `Delivered`            | `2`  | `Delivered`     |
| `Expired`              | `3`  | `Expired`       |
| `Deleted`              | `4`  | `Rejected`      |
| `Undeliverable`        | `5`  | `Undelivered`   |
| `Accepted`             | `6`  | `Sent`          |
| `Unknown`              | `7`  | `Unknown`       |
| `Rejected`             | `8`  | `Rejected`      |

#### Errors

SMSGate uses normal HTTP errors plus structured success/status payloads. The
adapter translates all failures into `ProviderException`:

| Situation                             | Exception                  | `getProviderCode()` |
| ------------------------------------- | -------------------------- | ------------------- |
| Missing sender / invalid metadata     | `InvalidMessageException`  | –                   |
| Transport failure                     | `ProviderException`        | `null`              |
| HTTP 4xx / 5xx                        | `ProviderException`        | HTTP status code    |
| `MessageError: true` in send response | `ProviderException`        | `MessageResult`     |
| `CommandStatus != OK` in status       | `ProviderException`        | `CommandStatus`     |
| Missing `MessageId` / `MessageState`  | `ProviderException`        | HTTP status code    |

```php
use SmsGateway\Exception\ProviderException;

try {
    $sender->send('+992900900900', 'Hello');
} catch (ProviderException $e) {
    match ($e->getProviderCode()) {
        '401' => $logger->alert('SMSGate API key rejected'),
        'InvalidSenderAddress' => $logger->warning('Sender is not registered'),
        'NOT_FOUND' => $logger->notice('Message status not found'),
        default => $logger->error('SMSGate failure', ['exception' => $e]),
    };
}
```

#### Status tracking

SMSGate uses a simple one-part `MessageId`, so unlike OsonSMS there is no
special encoding: the `MessageId` returned by `send()` is passed unchanged into
`getStatus()`.

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;

$result = $sender->send('+992900900900', 'Hello');

$provider = $sender->provider();
assert($provider instanceof TracksSmsStatusInterface);

$status = $provider->getStatus($result->messageId);
```

#### Operational notes

- `SenderAddress` is required and must already be registered for your account.
- The API docs mention both a primary host (`https://sms2.aliftech.net/`) and a
  reserve host (`https://smsgate.tj/`); the adapter exposes both as constants.
- Bulk sending (`POST /api/v1/sms/bulk`) is documented by SMSGate but is out of
  scope for the current shared contracts, so this adapter currently exposes only
  single-send + status.

## Core concepts

### Message lifecycle

Every provider reports one of the normalized states defined by `MessageStatus`:

| Status        | Meaning                                                          | Final? |
| ------------- | ---------------------------------------------------------------- | ------ |
| `Queued`      | Accepted by the provider, waiting to be dispatched.              | no     |
| `Sent`        | Dispatched to the carrier network.                               | no     |
| `Delivered`   | Delivery confirmed by the carrier.                               | yes    |
| `Rejected`    | Rejected by the provider before dispatch.                        | yes    |
| `Undelivered` | Dispatched but the carrier could not deliver it.                 | yes    |
| `Failed`      | Provider returned a permanent send failure.                      | yes    |
| `Expired`     | TTL / validity window expired before delivery.                   | yes    |
| `Unknown`     | Provider cannot report a status for this message.                | no     |

The enum exposes two helpers: `isFinal()` (useful for status polling loops)
and `isSuccessful()` (true for `Sent` and `Delivered`).

### Exceptions

Every exception this library throws implements the `SmsGatewayException`
marker interface, so you can catch all library errors uniformly:

```php
use SmsGateway\Exception\SmsGatewayException;

try {
    $sender->send('+992937123456', 'Hi');
} catch (SmsGatewayException $e) {
    // any failure that originated in this library or a provider adapter
}
```

More specific types:

- `InvalidMessageException` – a DTO was constructed with invalid input
  (empty recipient/text, blank sender). Client-side bug, not a provider error.
- `ProviderException` – provider runtime failure (HTTP error, auth, quota,
  invalid credentials, …). Carries `getProviderName()` and `getProviderCode()`.
- `MessageNotFoundException extends ProviderException` – status lookup for an
  id the provider doesn't recognize.
- `UnsupportedFeatureException` – the caller asked a provider to do something
  its contract does not support.

## Advanced usage

### Checking delivery status (providers that support it)

```php
use SmsGateway\Contracts\TracksSmsStatusInterface;
use SmsGateway\Enum\MessageStatus;

$provider = $sender->provider();

if ($provider instanceof TracksSmsStatusInterface) {
    $status = $provider->getStatus($result->messageId);

    if ($status->status->isFinal()) {
        // stop polling
    }
}
```

The built-in Payom adapter currently does not implement
`TracksSmsStatusInterface`, so the `instanceof` check naturally opts out. When
you wire in a provider that does, the same code path starts working — no API
changes.

### Building the outbound payload by hand

`SmsMessage` validates only the invariants the library can be certain about:

- `to` must be non-empty,
- `text` must be non-empty,
- `from` if provided must be non-empty.

Format-level rules (E.164, alphanumeric sender ids, length caps, encoding) are
delegated to provider adapters since they differ per vendor and country.

```php
use SmsGateway\DTO\SmsMessage;

$message = (new SmsMessage('+992900000000', 'Hello'))
    ->withMetadata(['priority' => 'high'])
    ->withMetadata(['client_ref' => 'abc-123']);

$sender->sendMessage($message);
```

### Injecting your own PSR-18 stack

The built-in providers auto-discover an HTTP stack, but every constructor
also accepts PSR-18 + PSR-17 objects directly. Use this path for DI
containers, unit tests, or when you need per-provider HTTP tuning:

```php
use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use SmsGateway\Providers\Payom\PayomSmsProvider;

$psr17 = new Psr17Factory();

$payom = new PayomSmsProvider(
    token: $_ENV['PAYOM_JWT_TOKEN'],
    defaultSenderName: 'payom.tj',
    httpClient: new Client(['timeout' => 10.0]),
    requestFactory: $psr17,
    streamFactory: $psr17,
);
```

## Authoring a custom provider

Third-party or internal providers integrate by implementing the shared
contracts directly — there is no registry, manager, or DSN format to conform
to. Once implemented, your provider plugs into `Sender` the same way a
built-in one does.

### Step 1 – Decide which capabilities you support

- Full provider → implement `SmsProviderInterface`.
- Send-only    → implement `SendsSmsInterface`.
- Status-only (e.g. a webhook-driven tracker) → implement
  `TracksSmsStatusInterface`.

### Step 2 – Implement the interface

```php
use DateTimeImmutable;
use SmsGateway\Contracts\SmsProviderInterface;
use SmsGateway\DTO\SendResult;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\DTO\StatusResult;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\MessageNotFoundException;
use SmsGateway\Exception\ProviderException;

final class AcmeSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private readonly AcmeHttpClient $http,
    ) {}

    public function getName(): string
    {
        return 'acme';
    }

    public function send(SmsMessage $message): SendResult
    {
        try {
            $response = $this->http->post('/sms/send', [
                'to'   => $message->to,
                'text' => $message->text,
                'from' => $message->from,
            ]);
        } catch (AcmeHttpException $e) {
            throw new ProviderException(
                message: 'Acme API request failed: ' . $e->getMessage(),
                providerName: $this->getName(),
                providerCode: $e->getCode() !== 0 ? (string) $e->getCode() : null,
                previous: $e,
            );
        }

        return new SendResult(
            messageId: $response['id'],
            status: $this->mapStatus($response['state']),
            providerName: $this->getName(),
            raw: $response,
        );
    }

    public function getStatus(string $messageId): StatusResult
    {
        $response = $this->http->get("/sms/{$messageId}");

        if ($response === null) {
            throw new MessageNotFoundException(
                message: "Message {$messageId} not found.",
                providerName: $this->getName(),
                providerCode: 'NOT_FOUND',
            );
        }

        return new StatusResult(
            messageId: $messageId,
            status: $this->mapStatus($response['state']),
            providerName: $this->getName(),
            updatedAt: new DateTimeImmutable($response['updated_at']),
            raw: $response,
        );
    }

    private function mapStatus(string $native): MessageStatus
    {
        return match ($native) {
            'accepted', 'pending' => MessageStatus::Queued,
            'sent'                 => MessageStatus::Sent,
            'delivered'            => MessageStatus::Delivered,
            'rejected'             => MessageStatus::Rejected,
            'undelivered'          => MessageStatus::Undelivered,
            'expired'              => MessageStatus::Expired,
            'failed', 'error'      => MessageStatus::Failed,
            default                => MessageStatus::Unknown,
        };
    }
}
```

### Step 3 – Use it through `Sender`

```php
$sender = new Sender(new AcmeSmsProvider($acmeHttp));
$sender->send('+992900000000', 'Hello from Acme');
```

### Authoring rules

1. Never leak vendor-specific exceptions. Translate them into
   `ProviderException` (or `MessageNotFoundException`) and pass the original
   as the `previous` argument so stack traces stay useful.
2. Never invent `SendResult` / `StatusResult` for failed requests — throw an
   exception instead. A result object always represents provider acceptance.
3. Always populate `providerName` in result DTOs with the same value returned
   by `getName()`. The library and downstream tooling rely on it for
   attribution and logging.
4. Keep `getName()` deterministic and stable. Use it as a machine-readable
   identifier (`"acme"`, `"osonsms"`, `"twilio"`), not a display label.
5. Map every native status into a `MessageStatus` case. Fall back to
   `MessageStatus::Unknown` instead of inventing new states.
6. Do not add provider-specific properties to the shared DTOs. Put anything
   vendor-specific inside `raw` or inside `SmsMessage::$metadata`.

## Package layout

```
src/
├── Sender.php                       # high-level facade: new Sender($provider)->send(...)
├── Contracts/
│   ├── SendsSmsInterface.php        # send(SmsMessage): SendResult
│   ├── TracksSmsStatusInterface.php # getStatus(string): StatusResult
│   └── SmsProviderInterface.php     # composite: send + status + name
├── DTO/
│   ├── SmsMessage.php               # outbound payload
│   ├── SendResult.php               # normalized send response
│   └── StatusResult.php             # normalized status snapshot
├── Enum/
│   └── MessageStatus.php            # queued|sent|delivered|rejected|...
├── Exception/
│   ├── SmsGatewayException.php      # marker interface for all library errors
│   ├── InvalidMessageException.php  # client-side DTO validation failures
│   ├── ProviderException.php        # provider runtime/API failures
│   ├── MessageNotFoundException.php # status lookup for unknown message id
│   └── UnsupportedFeatureException.php
└── Providers/
    ├── Payom/
    │   └── PayomSmsProvider.php     # send-only adapter for gateway.payom.tj
    ├── OsonSms/
    │   └── OsonSmsProvider.php      # send + status adapter for api.osonsms.com
    └── SmsGate/
        ├── SmsGateProvider.php      # send + status adapter for sms2.aliftech.net / smsgate.tj
        ├── SmsPriority.php          # typed wrapper for Priority values 0/1/2
        └── SmsType.php              # typed wrapper for SmsType values 1/2/3
```

## Development

```bash
composer install
composer test
```

The test suite uses PHPUnit 10. The in-memory
`tests/Fixtures/DummyProvider.php` doubles as the canonical reference
implementation for custom providers.

## Roadmap

- **Done:** abstraction layer, `Sender` facade, Payom.tj send-only adapter,
  OsonSMS send + status adapter, SMSGate send + status adapter, zero-config
  construction via `php-http/discovery`.
- More built-in provider adapters (added one at a time).
- Payom status tracking once the upstream API documents an endpoint for it.
- Optional orchestration helpers (manager, fallback chain, retry) that
  operate on `SmsProviderInterface` without changing it.
- Additional capability contracts (bulk send, templates, inbound SMS,
  webhooks) introduced via new interfaces so existing providers keep working.

## License

MIT
