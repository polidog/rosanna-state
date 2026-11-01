<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-3.12 raw text の中身で、親の RCDATA と RAWTEXT の要素を閉じられない */
final class RawTextAncestorTest extends TestCase
{
    use RendersInScope;

    private const ANCESTORS = ['noscript', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes'];

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    /** @return iterable<string, array{string, string}> */
    public static function ancestors(): iterable
    {
        foreach (self::ANCESTORS as $ancestor) {
            foreach (['style', 'script'] as $raw) {
                yield "{$raw} in {$ancestor}" => [$ancestor, $raw];
            }
        }
    }

    #[DataProvider('ancestors')]
    public function testRT312RawTextCannotCloseItsAncestor(string $ancestor, string $raw): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("</{$ancestor}");
        self::html(static fn() => new Element($ancestor, [], [new Element($raw, [], ["</{$ancestor}><img src=x onerror=alert(1)>"])]));
    }

    #[DataProvider('ancestors')]
    public function testRT312AncestorCheckIgnoresCaseAndChildSplitting(string $ancestor, string $raw): void
    {
        $upper = strtoupper($ancestor);

        $this->expectException(\RuntimeException::class);
        self::html(static fn() => new Element($upper, [], [new Element($raw, [], ['</', substr($ancestor, 0, 1), [strtoupper(substr($ancestor, 1))], '>'])]));
    }

    public function testRT312EveryAncestorCounts(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('</noscript');
        self::html(static fn() => new Element('noscript', [], [new Element('div', [], [new Element('title', [], [new Element('style', [], ['</noscript>'])])])]));
    }

    public function testRT312AFragmentDoesNotHideTheAncestor(): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => new Element('textarea', [], [new Element('Fragment', [], [new Element('style', [], ['</textarea>'])])]));
    }

    public function testRT312AnAncestorThatClosedNoLongerCounts(): void
    {
        self::assertSame(
            '<div><noscript></noscript><style></noscript></style></div>',
            self::html(static fn() => new Element('div', [], [new Element('noscript'), new Element('style', [], ['</noscript>'])])),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function ancestorNames(): iterable
    {
        foreach (self::ANCESTORS as $ancestor) {
            yield $ancestor => [$ancestor];
        }
    }

    #[DataProvider('ancestorNames')]
    public function testRT312HarmlessRawTextStaysUnescaped(string $ancestor): void
    {
        self::assertSame(
            "<{$ancestor}><style>a > b {}</style></{$ancestor}>",
            self::html(static fn() => new Element($ancestor, [], [new Element('style', [], ['a > b {}'])])),
        );
    }

    /** RT-3.1 通常のテキストの子と属性はエスケープされるので、親を閉じられない */
    #[DataProvider('ancestorNames')]
    public function testRT31OrdinaryChildrenOfTheseElementsAreEscaped(string $ancestor): void
    {
        $payload = "</{$ancestor}><img src=x onerror=alert(1)>";

        $html = self::html(static fn() => new Element($ancestor, [], [$payload, new Element('b', ['title' => $payload], [$payload])]));

        self::assertStringNotContainsString('<img', $html);
        self::assertSame(1, substr_count(strtolower($html), "</{$ancestor}"));
        self::assertStringContainsString('&lt;/' . $ancestor . '&gt;', $html);
    }

    /** RT-3.14 svg の中の style はエスケープされるので検査の対象にならない */
    public function testRT314ForeignContentIsEscapedRatherThanRefused(): void
    {
        $html = self::html(static fn() => new Element('noscript', [], [new Element('svg', [], [new Element('style', [], ['</noscript><img>'])])]));

        self::assertSame('<noscript><svg><style>&lt;/noscript&gt;&lt;img&gt;</style></svg></noscript>', $html);
    }

    /** RT-3.12 大文字のタグも raw text として扱う */
    public function testRT312UppercaseRawTagIsRawText(): void
    {
        self::assertSame('<STYLE>a > b</STYLE>', self::html(static fn() => new Element('STYLE', [], ['a > b'])));
        self::assertSame('<SCRIPT>a < b</SCRIPT>', self::html(static fn() => new Element('SCRIPT', [], ['a < b'])));

        $this->expectException(\RuntimeException::class);
        self::html(static fn() => new Element('SCRIPT', [], ['</ScRiPt>']));
    }

    public function testRT312HelperTagsGoThroughTheSameCheck(): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => H::textarea(children: [new Element('style', [], ['</TEXTAREA>'])]));
    }
}
