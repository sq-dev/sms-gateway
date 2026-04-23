<?php

declare(strict_types=1);

namespace SmsGateway\Laravel;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\DTO\SendResult;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\Providers\OsonSms\OsonSmsProvider;
use SmsGateway\Providers\Payom\PayomSmsProvider;
use SmsGateway\Providers\Aliftech\AliftechProvider;
use SmsGateway\Providers\Aliftech\SmsType;
use SmsGateway\Sender;

/**
 * Resolves named SMS connections from Laravel configuration and wraps each
 * one in a {@see Sender} so call sites read like `SmsGateway::send(...)` or
 * `SmsGateway::provider('payom')->send(...)`.
 *
 * The manager is deliberately small and framework-idiomatic:
 *
 *  - It knows how to build the library's built-in provider adapters
 *    ({@see PayomSmsProvider}, {@see OsonSmsProvider}, {@see AliftechProvider})
 *    from a plain configuration array that mirrors their constructors.
 *  - Consumers can register custom drivers with {@see self::extend()} without
 *    subclassing anything.
 *  - Resolved {@see Sender} instances are cached per connection name so
 *    repeated `provider()` calls return the same object.
 */
final class SmsGatewayManager
{
    /**
     * @var array<string, Sender>
     */
    private array $senders = [];

    /**
     * @var array<string, Closure>
     */
    private array $customCreators = [];

    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed> $config The full `sms-gateway` config array - typically
     *                                     the return value of `config('sms-gateway')`.
     *                                     Expected keys: `default` (string) and
     *                                     `connections` (array<string, array>).
     */
    public function __construct(
        private readonly Container $container,
        array $config,
    ) {
        $this->config = $config;
    }

    /**
     * Get the configured default connection name.
     *
     * @throws RuntimeException When the `default` key is missing or empty.
     */
    public function getDefaultConnection(): string
    {
        $default = $this->config['default'] ?? null;

        if (!is_string($default) || trim($default) === '') {
            throw new RuntimeException(
                'SMS gateway default connection is not configured. Set "default" in '
                . 'config/sms-gateway.php or SMS_GATEWAY_CONNECTION in your environment.',
            );
        }

        return $default;
    }

    /**
     * Override the default connection at runtime.
     *
     * Useful in tests or middleware where the current tenant determines which
     * SMS credentials to use.
     */
    public function setDefaultConnection(string $name): void
    {
        $this->config['default'] = $name;
    }

    /**
     * Resolve a {@see Sender} for the given connection name, or for the
     * default connection when `$name` is null.
     *
     * Resolution is cached: calling `provider('x')` twice returns the same
     * instance so tests can assert delivery and so stateful provider
     * internals (HTTP clients, discovery, …) are not rebuilt needlessly.
     *
     * @throws RuntimeException         When the default connection is requested but not configured.
     * @throws InvalidArgumentException When the connection is unknown or has an invalid driver.
     */
    public function provider(?string $name = null): Sender
    {
        $name ??= $this->getDefaultConnection();

        return $this->senders[$name] ??= $this->resolve($name);
    }

    /**
     * Alias for {@see self::provider()} that matches Laravel's
     * `MailManager::mailer()`, `DatabaseManager::connection()` vocabulary.
     */
    public function connection(?string $name = null): Sender
    {
        return $this->provider($name);
    }

    /**
     * Register a custom driver factory.
     *
     * The callback receives the container, the connection's config array,
     * and the connection name, and must return a {@see SendsSmsInterface}.
     * The manager then wraps that provider in a {@see Sender} and caches it.
     *
     * ```php
     * SmsGateway::extend('in-memory', function ($app, array $config, string $name) {
     *     return new MyQueuedSmsProvider($app->make('queue'));
     * });
     * ```
     *
     * @param Closure(Container, array<string, mixed>, string): SendsSmsInterface $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->customCreators[$driver] = $factory;
    }

    /**
     * Send a message using positional arguments against the default connection.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws \SmsGateway\Exception\SmsGatewayException
     */
    public function send(
        string $to,
        string $text,
        ?string $from = null,
        array $metadata = [],
    ): SendResult {
        return $this->provider()->send($to, $text, $from, $metadata);
    }

    /**
     * Send a pre-built {@see SmsMessage} through the default connection.
     *
     * @throws \SmsGateway\Exception\SmsGatewayException
     */
    public function sendMessage(SmsMessage $message): SendResult
    {
        return $this->provider()->sendMessage($message);
    }

    /**
     * Build a {@see Sender} for the given connection. Only called once per
     * connection name thanks to the `$senders` cache in {@see self::provider()}.
     */
    private function resolve(string $name): Sender
    {
        $connections = $this->config['connections'] ?? [];

        if (!is_array($connections) || !array_key_exists($name, $connections)) {
            throw new InvalidArgumentException(sprintf(
                'SMS gateway connection "%s" is not configured.',
                $name,
            ));
        }

        $config = $connections[$name];

        if (!is_array($config)) {
            throw new InvalidArgumentException(sprintf(
                'SMS gateway connection "%s" must be an array, got %s.',
                $name,
                get_debug_type($config),
            ));
        }

        $driver = $config['driver'] ?? null;

        if (!is_string($driver) || trim($driver) === '') {
            throw new InvalidArgumentException(sprintf(
                'SMS gateway connection "%s" is missing a non-empty "driver" key.',
                $name,
            ));
        }

        return new Sender($this->createProvider($driver, $config, $name));
    }

    /**
     * Dispatch driver creation to either a custom extension or one of the
     * built-in provider adapters.
     *
     * @param array<string, mixed> $config
     */
    private function createProvider(string $driver, array $config, string $connectionName): SendsSmsInterface
    {
        if (isset($this->customCreators[$driver])) {
            $provider = ($this->customCreators[$driver])($this->container, $config, $connectionName);

            if (!$provider instanceof SendsSmsInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Custom driver "%s" must return an instance of %s, got %s.',
                    $driver,
                    SendsSmsInterface::class,
                    get_debug_type($provider),
                ));
            }

            return $provider;
        }

        return match ($driver) {
            'payom' => $this->makePayom($config),
            'osonsms' => $this->makeOsonSms($config),
            'aliftech' => $this->makeAliftech($config),
            default => throw new InvalidArgumentException(sprintf(
                'SMS gateway driver "%s" is not supported. Built-in drivers: '
                . '"payom", "osonsms", "aliftech". Use SmsGatewayManager::extend() to register custom drivers.',
                $driver,
            )),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makePayom(array $config): PayomSmsProvider
    {
        return new PayomSmsProvider(
            token: (string) ($config['token'] ?? ''),
            defaultSenderName: $this->optionalString($config, 'default_sender_name'),
            baseUri: $this->optionalString($config, 'base_uri') ?? PayomSmsProvider::DEFAULT_BASE_URI,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeOsonSms(array $config): OsonSmsProvider
    {
        return new OsonSmsProvider(
            token: (string) ($config['token'] ?? ''),
            login: (string) ($config['login'] ?? ''),
            defaultSenderName: $this->optionalString($config, 'default_sender_name'),
            baseUri: $this->optionalString($config, 'base_uri') ?? OsonSmsProvider::DEFAULT_BASE_URI,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeAliftech(array $config): AliftechProvider
    {
        return new AliftechProvider(
            apiKey: (string) ($config['api_key'] ?? ''),
            defaultSenderName: $this->optionalString($config, 'default_sender_name'),
            defaultSmsType: $this->resolveSmsType($config['default_sms_type'] ?? null),
            baseUri: $this->optionalString($config, 'base_uri') ?? AliftechProvider::DEFAULT_BASE_URI,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'SMS gateway configuration key "%s" must be a string or null, got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        return $value === '' ? null : $value;
    }

    private function resolveSmsType(mixed $value): SmsType
    {
        if ($value === null || $value === '') {
            return SmsType::Common;
        }

        if ($value instanceof SmsType) {
            return $value;
        }

        if (is_int($value)) {
            return SmsType::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
                'Invalid SMS gateway "default_sms_type" integer value: %d. Expected 1 (common), 2 (otp), or 3 (batch).',
                $value,
            ));
        }

        if (is_string($value)) {
            if (is_numeric($value)) {
                return SmsType::tryFrom((int) $value) ?? throw new InvalidArgumentException(sprintf(
                    'Invalid SMS gateway "default_sms_type" numeric value: %s. Expected 1 (common), 2 (otp), or 3 (batch).',
                    $value,
                ));
            }

            $normalized = strtolower(trim($value));
            foreach (SmsType::cases() as $case) {
                if (strtolower($case->name) === $normalized) {
                    return $case;
                }
            }

            throw new InvalidArgumentException(sprintf(
                'Invalid SMS gateway "default_sms_type" string value: "%s". Expected one of: common, otp, batch.',
                $value,
            ));
        }

        throw new InvalidArgumentException(sprintf(
            'Invalid SMS gateway "default_sms_type" value of type %s. '
            . 'Expected SmsType enum, integer 1-3, or string "common"|"otp"|"batch".',
            get_debug_type($value),
        ));
    }
}
