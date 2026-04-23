<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SmsGateway\Exception\InvalidMessageException;
use SmsGateway\Exception\MessageNotFoundException;
use SmsGateway\Exception\ProviderException;
use SmsGateway\Exception\SmsGatewayException;
use SmsGateway\Exception\UnsupportedFeatureException;
use Throwable;

#[CoversClass(ProviderException::class)]
#[CoversClass(MessageNotFoundException::class)]
#[CoversClass(InvalidMessageException::class)]
#[CoversClass(UnsupportedFeatureException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    public function test_all_library_exceptions_share_the_marker_interface(): void
    {
        self::assertInstanceOf(SmsGatewayException::class, new InvalidMessageException('x'));
        self::assertInstanceOf(SmsGatewayException::class, new ProviderException('x'));
        self::assertInstanceOf(SmsGatewayException::class, new MessageNotFoundException('x'));
        self::assertInstanceOf(SmsGatewayException::class, new UnsupportedFeatureException('x'));
    }

    public function test_marker_interface_extends_throwable(): void
    {
        $reflection = new \ReflectionClass(SmsGatewayException::class);

        self::assertTrue(
            $reflection->implementsInterface(Throwable::class),
            'SmsGatewayException must extend Throwable so it can always be thrown.',
        );
    }

    public function test_message_not_found_is_a_provider_exception(): void
    {
        self::assertInstanceOf(ProviderException::class, new MessageNotFoundException('x'));
    }

    public function test_provider_exception_preserves_context(): void
    {
        $previous = new \RuntimeException('http 500');

        $exception = new ProviderException(
            message: 'Upstream failure',
            providerName: 'acme',
            providerCode: 'ERR_42',
            previous: $previous,
        );

        self::assertSame('Upstream failure', $exception->getMessage());
        self::assertSame('acme', $exception->getProviderName());
        self::assertSame('ERR_42', $exception->getProviderCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
