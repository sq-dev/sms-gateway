<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmsGateway\Enum\MessageStatus;

#[CoversClass(MessageStatus::class)]
final class MessageStatusTest extends TestCase
{
    public function test_is_string_backed_for_persistence(): void
    {
        self::assertSame('queued', MessageStatus::Queued->value);
        self::assertSame('delivered', MessageStatus::Delivered->value);
    }

    /**
     * @return iterable<string, array{0: MessageStatus, 1: bool}>
     */
    public static function finalCases(): iterable
    {
        yield 'Queued is not final'      => [MessageStatus::Queued, false];
        yield 'Sent is not final'        => [MessageStatus::Sent, false];
        yield 'Unknown is not final'     => [MessageStatus::Unknown, false];
        yield 'Delivered is final'       => [MessageStatus::Delivered, true];
        yield 'Rejected is final'        => [MessageStatus::Rejected, true];
        yield 'Undelivered is final'     => [MessageStatus::Undelivered, true];
        yield 'Failed is final'          => [MessageStatus::Failed, true];
        yield 'Expired is final'         => [MessageStatus::Expired, true];
    }

    #[DataProvider('finalCases')]
    public function test_is_final(MessageStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isFinal());
    }

    /**
     * @return iterable<string, array{0: MessageStatus, 1: bool}>
     */
    public static function successfulCases(): iterable
    {
        yield 'Sent is successful'        => [MessageStatus::Sent, true];
        yield 'Delivered is successful'   => [MessageStatus::Delivered, true];
        yield 'Queued is not successful'  => [MessageStatus::Queued, false];
        yield 'Rejected is not successful'=> [MessageStatus::Rejected, false];
        yield 'Failed is not successful'  => [MessageStatus::Failed, false];
        yield 'Unknown is not successful' => [MessageStatus::Unknown, false];
    }

    #[DataProvider('successfulCases')]
    public function test_is_successful(MessageStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isSuccessful());
    }
}
