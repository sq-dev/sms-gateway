<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\InvalidMessageException;
use SmsGateway\Exception\ProviderException;
use SmsGateway\Sender;
use SmsGateway\Tests\Fixtures\DummyProvider;

#[CoversClass(Sender::class)]
final class SenderTest extends TestCase
{
    public function test_send_forwards_positional_args_to_provider(): void
    {
        $provider = new DummyProvider(name: 'dummy');
        $sender = new Sender($provider);

        $result = $sender->send('+992900000000', 'Hello');

        self::assertSame(MessageStatus::Queued, $result->status);
        self::assertSame('dummy', $result->providerName);

        self::assertCount(1, $provider->sentMessages);
        self::assertSame('+992900000000', $provider->sentMessages[0]->to);
        self::assertSame('Hello', $provider->sentMessages[0]->text);
        self::assertNull($provider->sentMessages[0]->from);
        self::assertSame([], $provider->sentMessages[0]->metadata);
    }

    public function test_send_propagates_optional_sender_and_metadata(): void
    {
        $provider = new DummyProvider();
        $sender = new Sender($provider);

        $sender->send(
            to: '+992900000000',
            text: 'Hi',
            from: 'ACME',
            metadata: ['priority' => 'high'],
        );

        self::assertCount(1, $provider->sentMessages);
        self::assertSame('ACME', $provider->sentMessages[0]->from);
        self::assertSame(['priority' => 'high'], $provider->sentMessages[0]->metadata);
    }

    public function test_send_message_delegates_to_provider_without_rebuilding_dto(): void
    {
        $provider = new DummyProvider();
        $sender = new Sender($provider);

        $message = new SmsMessage('+992900000000', 'Hi', metadata: ['priority' => 'low']);
        $sender->sendMessage($message);

        self::assertCount(1, $provider->sentMessages);
        self::assertSame(
            $message,
            $provider->sentMessages[0],
            'sendMessage must pass the DTO through unchanged.',
        );
    }

    public function test_send_propagates_invalid_message_exception(): void
    {
        $sender = new Sender(new DummyProvider());

        $this->expectException(InvalidMessageException::class);

        $sender->send('', 'Hello');
    }

    public function test_send_propagates_provider_exceptions(): void
    {
        $sender = new Sender(new DummyProvider(failNextSend: true));

        $this->expectException(ProviderException::class);

        $sender->send('+992900000000', 'Hello');
    }

    public function test_provider_getter_returns_the_injected_instance(): void
    {
        $provider = new DummyProvider(name: 'dummy');
        $sender = new Sender($provider);

        self::assertSame($provider, $sender->provider());
    }
}
