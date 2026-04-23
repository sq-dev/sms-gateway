<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit\DTO;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SmsGateway\DTO\SendResult;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\InvalidMessageException;

#[CoversClass(SendResult::class)]
final class SendResultTest extends TestCase
{
    public function test_exposes_all_fields(): void
    {
        $result = new SendResult(
            messageId: 'msg-123',
            status: MessageStatus::Queued,
            providerName: 'dummy',
            raw: ['accepted' => true],
        );

        self::assertSame('msg-123', $result->messageId);
        self::assertSame(MessageStatus::Queued, $result->status);
        self::assertSame('dummy', $result->providerName);
        self::assertSame(['accepted' => true], $result->raw);
    }

    public function test_raw_defaults_to_empty_array(): void
    {
        $result = new SendResult(
            messageId: 'msg-123',
            status: MessageStatus::Sent,
            providerName: 'dummy',
        );

        self::assertSame([], $result->raw);
    }

    public function test_rejects_empty_message_id(): void
    {
        $this->expectException(InvalidMessageException::class);

        new SendResult(
            messageId: '   ',
            status: MessageStatus::Sent,
            providerName: 'dummy',
        );
    }

    public function test_rejects_empty_provider_name(): void
    {
        $this->expectException(InvalidMessageException::class);

        new SendResult(
            messageId: 'msg-123',
            status: MessageStatus::Sent,
            providerName: '',
        );
    }
}
