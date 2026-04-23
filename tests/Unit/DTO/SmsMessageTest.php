<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Unit\DTO;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmsGateway\DTO\SmsMessage;
use SmsGateway\Exception\InvalidMessageException;
use SmsGateway\Exception\SmsGatewayException;

#[CoversClass(SmsMessage::class)]
final class SmsMessageTest extends TestCase
{
    public function test_constructs_with_required_fields_only(): void
    {
        $message = new SmsMessage(to: '+992900000000', text: 'hello');

        self::assertSame('+992900000000', $message->to);
        self::assertSame('hello', $message->text);
        self::assertNull($message->from);
        self::assertSame([], $message->metadata);
    }

    public function test_accepts_optional_sender_and_metadata(): void
    {
        $message = new SmsMessage(
            to: '+992900000000',
            text: 'hello',
            from: 'ACME',
            metadata: ['flash' => true, 'dlr' => 'https://example.test/dlr'],
        );

        self::assertSame('ACME', $message->from);
        self::assertSame(['flash' => true, 'dlr' => 'https://example.test/dlr'], $message->metadata);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty recipient'          => ['', 'hello', null];
        yield 'whitespace recipient'     => ["   \t\n", 'hello', null];
        yield 'empty text'               => ['+992900000000', '', null];
        yield 'blank sender'             => ['+992900000000', 'hello', '   '];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_input(string $to, string $text, ?string $from): void
    {
        $this->expectException(InvalidMessageException::class);

        new SmsMessage(to: $to, text: $text, from: $from);
    }

    public function test_invalid_message_exception_is_catchable_as_library_marker(): void
    {
        try {
            new SmsMessage(to: '', text: 'hello');
            self::fail('Expected InvalidMessageException to be thrown.');
        } catch (SmsGatewayException $exception) {
            self::assertInstanceOf(InvalidMessageException::class, $exception);
        }
    }

    public function test_with_metadata_returns_new_instance_and_merges_keys(): void
    {
        $message = new SmsMessage(
            to: '+992900000000',
            text: 'hello',
            metadata: ['priority' => 'low', 'flash' => true],
        );

        $enriched = $message->withMetadata(['priority' => 'high', 'client_ref' => 'abc']);

        self::assertNotSame($message, $enriched);
        self::assertSame(
            ['priority' => 'low', 'flash' => true],
            $message->metadata,
            'Original DTO must stay immutable after withMetadata.',
        );
        self::assertSame(
            ['priority' => 'high', 'flash' => true, 'client_ref' => 'abc'],
            $enriched->metadata,
        );
    }
}
