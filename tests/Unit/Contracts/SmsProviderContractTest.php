<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SmsGateway\Contracts\SendsSmsInterface;
use SmsGateway\Contracts\SmsProviderInterface;
use SmsGateway\Contracts\TracksSmsStatusInterface;
use SmsGateway\DTO\SendResult;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\DTO\StatusResult;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\MessageNotFoundException;
use SmsGateway\Exception\ProviderException;
use SmsGateway\Tests\Fixtures\DummyProvider;

/**
 * Exercises the shared contracts through the in-memory DummyProvider. This guards
 * against accidental breakage of the public API shape (method names, parameter
 * types, exception types) without requiring any real SMS backend.
 */
#[CoversNothing]
final class SmsProviderContractTest extends TestCase
{
    public function test_composite_interface_extends_the_split_contracts(): void
    {
        $provider = new DummyProvider();

        self::assertInstanceOf(SmsProviderInterface::class, $provider);
        self::assertInstanceOf(SendsSmsInterface::class, $provider);
        self::assertInstanceOf(TracksSmsStatusInterface::class, $provider);
    }

    public function test_send_returns_a_send_result_and_registers_status(): void
    {
        $provider = new DummyProvider(name: 'dummy', initialStatus: MessageStatus::Queued);

        $sendResult = $provider->send(new SmsMessage('+992900000000', 'hello'));

        self::assertInstanceOf(SendResult::class, $sendResult);
        self::assertSame('dummy', $sendResult->providerName);
        self::assertSame(MessageStatus::Queued, $sendResult->status);
        self::assertNotSame('', $sendResult->messageId);

        $statusResult = $provider->getStatus($sendResult->messageId);

        self::assertInstanceOf(StatusResult::class, $statusResult);
        self::assertSame($sendResult->messageId, $statusResult->messageId);
        self::assertSame('dummy', $statusResult->providerName);
        self::assertSame(MessageStatus::Queued, $statusResult->status);
    }

    public function test_status_reflects_lifecycle_transitions(): void
    {
        $provider = new DummyProvider();
        $sendResult = $provider->send(new SmsMessage('+992900000000', 'hello'));

        $provider->transition($sendResult->messageId, MessageStatus::Delivered);

        $status = $provider->getStatus($sendResult->messageId);

        self::assertSame(MessageStatus::Delivered, $status->status);
        self::assertTrue($status->status->isFinal());
    }

    public function test_status_lookup_throws_message_not_found_for_unknown_ids(): void
    {
        $provider = new DummyProvider(name: 'dummy');

        $this->expectException(MessageNotFoundException::class);

        $provider->getStatus('non-existent');
    }

    public function test_send_translates_underlying_failure_into_provider_exception(): void
    {
        $provider = new DummyProvider(name: 'dummy', failNextSend: true);

        $this->expectException(ProviderException::class);

        $provider->send(new SmsMessage('+992900000000', 'hello'));
    }

    public function test_provider_name_is_stable(): void
    {
        $provider = new DummyProvider(name: 'osonsms');

        self::assertSame('osonsms', $provider->getName());
        self::assertSame('osonsms', $provider->getName(), 'getName must be deterministic.');
    }
}
