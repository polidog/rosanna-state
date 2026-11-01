<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Response;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\FunctionComponent;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Protocol\Support\ActionRequests;

/** HD-6 replay の木の探し方、SN-18 の適用範囲、AC-16 と AC-18 の連続の適用 */
final class ReplaySearchTest extends TestCase
{
    use ActionRequests;

    private const SECRET = 'replay-search-secret';

    protected function setUp(): void
    {
        $this->captureLog();
        RequestLifecycle::reset();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
        $this->releaseLog();
    }

    private static function app(): Rosanna
    {
        return new Rosanna()->setSnapshotSecret(self::SECRET);
    }

    private static function counter(string $key): FunctionComponent
    {
        return fc(static function (array $props): Element {
            [$count, $set] = useState(0);

            return H::div(children: [H::span(children: 'who=' . ($props['who'] ?? '-') . ' count=' . $count), H::button(onClick: static fn() => $set($count + 1), children: 'inc')]);
        }, key: $key, storageType: StorageType::Snapshot);
    }

    private static function snapshotOf(string $html, string $key): string
    {
        foreach (self::dom($html)->querySelectorAll('[data-rosanna-snapshot]') as $wrapper) {
            if (str_ends_with((string) $wrapper->getAttribute('data-rosanna'), '#' . $key)) {
                return (string) $wrapper->getAttribute('data-rosanna-snapshot');
            }
        }
        self::fail("No snapshot for {$key} in {$html}");
    }

    /** @return array<string, mixed> */
    private static function pressOn(string $html, string $key): array
    {
        $count = self::dom($html)->querySelectorAll('button[name="_rosanna_action"]')->length;
        for ($i = 0; $i < $count; $i++) {
            $fields = self::press($html, $i);
            if (str_ends_with((string) $fields['_rosanna_component'], '#' . $key)) {
                return $fields;
            }
        }
        self::fail("No button for {$key} in {$html}");
    }

    private static function assertRejected(?Response $response): void
    {
        self::assertNotNull($response);
        self::assertSame(400, $response->status, $response->body);
        self::assertSame('Invalid snapshot', $response->body);
    }

    // --- SN-18 ---

    /** @return array{Rosanna, \Closure(): string} 2 つの根を別々に描画する host */
    private static function twoRoots(): array
    {
        $app = self::app();
        $first = self::counter('first');
        $second = self::counter('second');

        return [$app, static fn(): string => $app->renderElement($first()) . $app->renderElement($second())];
    }

    public function testSN18AStringReplayChecksTheRootsAfterTheTarget(): void
    {
        [$app, $page] = self::twoRoots();
        $html = self::page($app, $page);
        $fields = self::pressOn($html, 'first');
        $fields['_rosanna_snapshots'] = [self::snapshotOf($html, 'second')];
        RequestLifecycle::reset();

        self::assertRejected($app->actions()->handle(self::post($fields, partial: true), replay: static fn(): string => $page()));
    }

    public function testSN18AStringReplayChecksTheRootsBeforeTheTarget(): void
    {
        [$app, $page] = self::twoRoots();
        $html = self::page($app, $page);
        $fields = self::pressOn($html, 'second');
        $fields['_rosanna_snapshots'] = [self::snapshotOf($html, 'first')];
        RequestLifecycle::reset();

        self::assertRejected($app->actions()->handle(self::post($fields, partial: true), replay: static fn(): string => $page()));
    }

    /** 祖先の描画の中で子を文字列にする host では、子の根が先に集まる */
    public function testSN18AnAncestorThatRendersTheTargetToAStringIsStillOutside(): void
    {
        $app = self::app();
        $child = self::counter('child');
        $ancestor = fc(static function () use ($app, $child): Element {
            [$who, $set] = useState('guest');
            echo $app->renderElement($child(['who' => $who]));

            return H::div(children: [H::span(children: 'who=' . $who), H::button(onClick: static fn() => $set('admin'), children: 'become')]);
        }, key: 'ancestor', storageType: StorageType::Snapshot);
        $page = static fn(): string => $app->renderElement($ancestor());

        ob_start();
        $html = self::page($app, $page);
        $html = (string) ob_get_clean() . $html;
        $fields = self::pressOn($html, 'child');
        $fields['_rosanna_snapshots'] = [self::snapshotOf($html, 'ancestor')];
        RequestLifecycle::reset();

        self::assertRejected($app->actions()->handle(self::post($fields, partial: true), replay: static fn(): string => $page()));
    }

    /** 戻り値の Element と renderElement() の木の両方を調べる */
    public function testSN18AReturnedElementAndARenderedTreeAreBothChecked(): void
    {
        $app = self::app();
        $target = self::counter('target');
        $other = self::counter('other');
        $page = static function () use ($app, $target, $other): Element {
            echo $app->renderElement($other());

            return H::main(children: [$target()]);
        };
        ob_start();
        $html = self::page($app, $page);
        $html = (string) ob_get_clean() . $html;
        $fields = self::pressOn($html, 'target');
        $fields['_rosanna_snapshots'] = [self::snapshotOf($html, 'other')];
        RequestLifecycle::reset();

        self::assertRejected($app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $page()));
    }

    public function testSN18ADescendantInAStringReplayIsStillAccepted(): void
    {
        $app = self::app();
        $child = self::counter('child');
        $parent = fc(static function () use ($child): Element {
            [$count, $set] = useState(0);

            return H::div(children: [H::button(onClick: static fn() => $set($count + 1), children: "parent={$count}"), $child()]);
        }, key: 'parent', storageType: StorageType::Snapshot);
        $unrelated = self::counter('unrelated');
        $page = static fn(): string => $app->renderElement($parent()) . $app->renderElement($unrelated());
        $html = self::page($app, $page);
        $fields = self::pressOn($html, 'parent');
        $fields['_rosanna_snapshots'] = [self::snapshotOf($html, 'child')];
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): string => $page());

        self::assertNotNull($response);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('parent=1', $response->body);
    }

    // --- HD-6 ---

    public function testHD6AReturnedElementDoesNotHideARenderedTree(): void
    {
        $app = self::app();
        $target = self::counter('target');
        $page = static function () use ($app, $target): Element {
            echo $app->renderElement($target());

            return H::p(children: 'layout');
        };
        ob_start();
        $html = self::page($app, $page);
        $html = (string) ob_get_clean() . $html;
        $fields = self::pressOn($html, 'target');
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('count=1', $response->body);
        self::assertStringNotContainsString('layout', $response->body);
    }

    // --- AC-16、AC-18、VR-8 ---

    private static function rowList(StorageType $storage): FunctionComponent
    {
        return fc(static function (): Element {
            $initial = [];
            for ($i = 1; $i <= 12; $i++) {
                $initial[] = ['id' => $i, 'on' => false, 'label' => 'row number ' . $i];
            }
            [$rows, $set] = useState($initial);
            $buttons = [];
            foreach ($rows as $i => $row) {
                $buttons[] = H::button(onClick: static function () use ($rows, $i, $set) {
                    $next = $rows;
                    $next[$i]['on'] = !$next[$i]['on'];

                    return $set($next);
                }, children: $row['id'] . ':' . ($row['on'] ? 'on' : 'off'));
            }

            return H::div(children: $buttons);
        }, key: 'rows', storageType: $storage);
    }

    /** AC-16、AC-18 断片のフォームを続けて送っても毎回適用される。VR-8 Memory は照合しない */
    public function testAC18MemoryDiffAppliesOnEveryPartialInARow(): void
    {
        $app = self::app();
        $component = self::rowList(StorageType::Memory);
        $html = self::page($app, static fn() => $component());

        foreach ([0, 1, 2, 3] as $row) {
            $fields = self::press($html, $row);
            self::assertArrayHasKey('_rosanna_base', $fields, "row {$row}");
            RequestLifecycle::reset();

            $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());

            self::assertNotNull($response);
            self::assertSame(200, $response->status);
            self::assertArrayNotHasKey('X-Rosanna-Stale', $response->headers, "row {$row}");
            for ($on = 0; $on <= $row; $on++) {
                self::assertStringContainsString(($on + 1) . ':on', $response->body, "row {$row}");
            }
            $html = $response->body;
            RequestLifecycle::reset();
        }
    }
}
