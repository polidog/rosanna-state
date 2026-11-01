<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\State\fc;

use Polidog\Rosanna\State\Storage\StorageType;
use Polidog\Rosanna\State\Tests\Runtime\Support\RendersInScope;

use function Polidog\Rosanna\State\useState;

/** RT-3 の出力 */
final class RendererOutputTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testRT31InvalidUtf8StateValueDoesNotBreakTheOutput(): void
    {
        $html = self::html(static fn() => fc(static function () {
            [$value, $set] = useState("ok\xFF");

            return H::button(onClick: static fn() => $set("ng\xFF"), children: $value);
        }, 'k')());

        self::assertStringContainsString('ok', $html);
        self::assertStringContainsString('_rosanna_action', $html);
    }

    public function testRT31InvalidUtf8SnapshotStateIsStillSigned(): void
    {
        $html = self::html(static fn() => fc(static function () {
            [$value] = useState("ok\xFF");

            return H::p(children: $value);
        }, 'k', StorageType::Snapshot)());

        self::assertStringContainsString('data-rosanna-snapshot=', $html);
        self::assertStringContainsString('checksum', $html);
    }
}
