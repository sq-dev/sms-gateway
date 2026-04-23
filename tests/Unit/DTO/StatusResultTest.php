<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit\DTO;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SmsGateway\DTO\StatusResult;
use SmsGateway\Enum\MessageStatus;
use SmsGateway\Exception\InvalidMessageException;

#[CoversClass(StatusResult::class)]
final class StatusResultTest extends TestCase
{
    public function test_exposes_all_fields(): void
    {
        $updatedAt = new DateTimeImmutable('2026-04-23T12:00:00+00:00');

        $result = new StatusResult(
            messageId: 'msg-123',
            status: MessageStatus::Delivered,
            providerName: 'dummy',
            updatedAt: $updatedAt,
            raw: ['carrier' => 'ACME'],
        );

        self::assertSame('msg-123', $result->messageId);
        self::assertSame(MessageStatus::Delivered, $result->status);
        self::assertSame('dummy', $result->providerName);
        self::assertSame($updatedAt, $result->updatedAt);
        self::assertSame(['carrier' => 'ACME'], $result->raw);
    }

    public function test_updated_at_and_raw_are_optional(): void
    {
        $result = new StatusResult(
            messageId: 'msg-123',
            status: MessageStatus::Unknown,
            providerName: 'dummy',
        );

        self::assertNull($result->updatedAt);
        self::assertSame([], $result->raw);
    }

    public function test_rejects_empty_message_id(): void
    {
        $this->expectException(InvalidMessageException::class);

        new StatusResult(
            messageId: '',
            status: MessageStatus::Unknown,
            providerName: 'dummy',
        );
    }

    public function test_rejects_empty_provider_name(): void
    {
        $this->expectException(InvalidMessageException::class);

        new StatusResult(
            messageId: 'msg-123',
            status: MessageStatus::Unknown,
            providerName: '   ',
        );
    }
}
