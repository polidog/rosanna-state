<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Protocol\ActionEnvelope;
use Polidog\Rosanna\Runtime\Action;

final class ActionEnvelopeTest extends TestCase
{
    public function testAC20FormAndRoundTripPreserveTheSignedBytes(): void
    {
        $json = Action::setState(0, ['日本語', '"quoted"', '</script>', 1.0, 'back\\slash'], 'C#0')->toJson();

        $envelope = new ActionEnvelope($json, 'sig');
        $decoded = ActionEnvelope::decode($envelope->toJson());

        self::assertNotNull($decoded);
        self::assertSame($json, $decoded->actionJson);
        self::assertSame('sig', $decoded->signature);
        self::assertSame(['action' => $json, 'sig' => 'sig'], json_decode($envelope->toJson(), true));
    }

    public function testAC20NullSignature(): void
    {
        $decoded = ActionEnvelope::decode(new ActionEnvelope('{}', null)->toJson());

        self::assertNotNull($decoded);
        self::assertNull($decoded->signature);
    }

    public function testAC21RestoreEnvelopeBuiltByTheClient(): void
    {
        $decoded = ActionEnvelope::decode('{"action":"{\"type\":\"restore\",\"payload\":[]}","sig":"abc"}');

        self::assertNotNull($decoded);
        self::assertSame('{"type":"restore","payload":[]}', $decoded->actionJson);
        self::assertSame('abc', $decoded->signature);
    }

    /** @return iterable<string, array{string}> */
    public static function notObjects(): iterable
    {
        yield 'relayer token' => ['rosanna-action:abc'];
        yield 'text' => ['abc'];
        yield 'list' => ['[1]'];
        yield 'empty list' => ['[]'];
        yield 'number' => ['1'];
        yield 'string' => ['"x"'];
        yield 'null' => ['null'];
        yield 'empty' => [''];
        yield 'broken' => ['{"action":'];
    }

    #[DataProvider('notObjects')]
    public function testAC22NonObjectIsNull(string $json): void
    {
        self::assertNull(ActionEnvelope::decode($json));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedObjects(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'no action' => ['{"sig":"x"}'];
        yield 'empty action' => ['{"action":"","sig":"x"}'];
        yield 'object action' => ['{"action":{"type":"restore"},"sig":"x"}'];
        yield 'null action' => ['{"action":null,"sig":"x"}'];
        yield 'no sig' => ['{"action":"{}"}'];
        yield 'numeric sig' => ['{"action":"{}","sig":1}'];
        yield 'array sig' => ['{"action":"{}","sig":["x"]}'];
    }

    #[DataProvider('malformedObjects')]
    public function testMalformedObjectIsInvalidArgument(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ActionEnvelope::decode($json);
    }

    public function testAC20BytesThatJsonCannotCarryAreRefusedNotReplaced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ActionEnvelope("bad\xFF", null)->toJson();
    }
}
