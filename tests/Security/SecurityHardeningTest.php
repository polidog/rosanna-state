<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Html\createElement;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** 出力の安全性と、イベントの prop の実行の防止 (RT-2.6、RT-3) */
final class SecurityHardeningTest extends TestCase
{
    use RendersInScope;

    public static int $invoked = 0;

    protected function setUp(): void
    {
        self::$invoked = 0;
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousUrls(): iterable
    {
        yield 'plain' => ['javascript:alert(1)'];
        yield 'upper' => ['JAVASCRIPT:alert(1)'];
        yield 'leading tab' => ["\tjavascript:alert(1)"];
        yield 'inner tab' => ["java\tscript:alert(1)"];
        yield 'inner newline' => ["java\nscript:alert(1)"];
        yield 'inner cr' => ["java\rscript:alert(1)"];
        yield 'leading space' => ['   javascript:alert(1)'];
        yield 'leading control' => ["\x01\x1Fjavascript:alert(1)"];
        yield 'leading nul' => ["\x00javascript:alert(1)"];
        yield 'vbscript' => ['VbScript:x'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'tab before colon' => ["javascript\t:alert(1)"];
    }

    #[DataProvider('dangerousUrls')]
    public function testRT311DangerousSchemesDropOnlyTheAttribute(string $url): void
    {
        foreach (['href', 'HREF', 'src', 'action', 'formaction', 'formAction', 'data', 'poster', 'background', 'xlink:href', 'xlinkHref', 'XLINK:HREF'] as $name) {
            self::assertSame('<a id="i"></a>', self::html(static fn() => new Element('a', ['id' => 'i', $name => $url])), $name);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function harmlessUrls(): iterable
    {
        yield 'https' => ['https://example.com/?a=1&b=2'];
        yield 'relative' => ['/path:with/colon'];
        yield 'path colon' => ['foo/bar:baz'];
        yield 'query colon' => ['?javascript:alert(1)'];
        yield 'fragment' => ['#javascript:x'];
        yield 'bad scheme' => ['java script:alert(1)'];
        yield 'leading colon' => [':javascript'];
        yield 'mailto' => ['mailto:a@example.com'];
        yield 'empty' => [''];
        yield 'tab is kept' => ["/a\tb"];
    }

    #[DataProvider('harmlessUrls')]
    public function testRT311HarmlessUrlsAreEmittedUnchanged(string $url): void
    {
        self::assertSame(
            '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></a>',
            self::html(static fn() => H::a(href: $url)),
        );
    }

    public function testRT311OtherAttributesAreNotInspected(): void
    {
        self::assertSame('<a title="javascript:x"></a>', self::html(static fn() => H::a(title: 'javascript:x')));
    }

    /** @return iterable<string, array{string}> */
    public static function rawEndTags(): iterable
    {
        yield 'script' => ['script'];
        yield 'style' => ['style'];
        yield 'upper tag' => ['SCRIPT'];
    }

    #[DataProvider('rawEndTags')]
    public function testRT312SplitEndTagIsRefusedAfterJoining(string $tag): void
    {
        $lower = strtolower($tag);
        foreach ([["</{$lower}>"], ['</', $lower, '>'], ['<', "/{$lower}"], ['x</' . strtoupper($lower)], [['</'], [[$lower]]]] as $children) {
            try {
                self::html(static fn() => new Element($tag, [], $children));
                self::fail('Expected a refusal for ' . json_encode($children));
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('must not contain', $e->getMessage());
            }
        }
    }

    public function testRT312RawTextIsNotEscaped(): void
    {
        self::assertSame('<script>if (a < b && c > "d") { e(); }</script>', self::html(static fn() => H::script(children: 'if (a < b && c > "d") { e(); }')));
        self::assertSame('<STYLE>a > b {}</STYLE>', self::html(static fn() => new Element('STYLE', [], ['a > b {}'])));
    }

    public function testRT314ForeignContentEscapesAndRecovers(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        $html = self::html(static fn() => H::Fragment([
            new Element('svg', [], [new Element('style', [], [$payload]), new Element('script', [], [$payload])]),
            new Element('style', [], ['a > b {}']),
        ]));

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<svg><style>&lt;img src=x onerror=alert(1)&gt;</style><script>&lt;img', $html);
        self::assertStringEndsWith('<style>a > b {}</style>', $html);
    }

    public function testRT314NestedForeignContentKeepsCountingUntilTheOutermostCloses(): void
    {
        $inner = new Element('math', [], [new Element('style', [], ['<i>'])]);
        $html = self::html(static fn() => H::Fragment([
            new Element('svg', [], [$inner, new Element('style', [], ['<b>'])]),
            new Element('SCRIPT', [], ['1<2']),
            new Element('MATH', [], [new Element('style', [], ['<u>'])]),
        ]));

        self::assertStringContainsString('<math><style>&lt;i&gt;</style></math><style>&lt;b&gt;</style></svg>', $html);
        self::assertStringContainsString('<SCRIPT>1<2</SCRIPT>', $html);
        self::assertStringContainsString('<MATH><style>&lt;u&gt;</style></MATH>', $html);
    }

    public function testRT314ThrowInForeignContentDoesNotLeakTheDepth(): void
    {
        $renderer = self::scope()->renderers->create('_root_');
        try {
            $renderer->renderElement(new Element('svg', [], [new \stdClass()]));
            self::fail();
        } catch (\RuntimeException) {
        }

        self::assertSame('<style><b></style>', $renderer->renderElement(new Element('style', [], ['<b>'])));
    }

    /** @return iterable<string, array{string}> */
    public static function badAttributeNames(): iterable
    {
        yield 'quote and space' => ['a" onclick="x'];
        yield 'angle' => ['a>'];
        yield 'whitespace' => ['a b'];
        yield 'equals' => ['a=b'];
        yield 'slash' => ['a/b'];
        yield 'empty' => [''];
        yield 'trailing newline' => ["a\n"];
    }

    #[DataProvider('badAttributeNames')]
    public function testRT38InvalidAttributeNameThrows(string $name): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => new Element('div', [$name => 'x']));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function inlineHandlers(): iterable
    {
        yield 'lower' => ['onclick', 'x()'];
        yield 'error' => ['onerror', 'x()'];
        yield 'camel' => ['onMouseOver', 'x()'];
        yield 'upper' => ['ONCLICK', 'x()'];
        yield 'bool true' => ['onload', true];
        yield 'bool false' => ['onload', false];
        yield 'number' => ['onload', 1];
        yield 'array' => ['onload', ['x']];
    }

    #[DataProvider('inlineHandlers')]
    public function testRT38InlineHandlersThrowWhateverTheValue(string $name, mixed $value): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inline event handler');
        self::html(static fn() => new Element('div', [$name => $value]));
    }

    public function testRT38DataAriaAndNamespacedNamesPass(): void
    {
        self::assertSame('<svg data-x="1" aria-label="a" xml:lang="ja" viewBox="0 0 1 1"></svg>', self::html(static fn() => new Element('svg', [
            'data-x' => '1', 'aria-label' => 'a', 'xml:lang' => 'ja', 'viewBox' => '0 0 1 1',
        ])));
    }

    public function testRT34InvalidTagOnActionElementThrowsBeforeSigning(): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => new Element('b"utton', ['wire:click' => Action::setState(0, 1)]));
    }

    public function testRT26EventPropRejectsStringCallableWithoutExecutingIt(): void
    {
        foreach (['phpinfo', 'system', __CLASS__ . '::touch'] as $callable) {
            try {
                createElement('div', ['onClick' => $callable]);
                self::fail($callable);
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(0, self::$invoked);
    }

    public function testRT26EventPropRejectsArrayCallable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            createElement('div', ['onChange' => [self::class, 'touch']]);
        } finally {
            self::assertSame(0, self::$invoked);
        }
    }

    public function testRT26EventPropRejectsInvokableObject(): void
    {
        $invokable = new class {
            public function __invoke(): void
            {
                SecurityHardeningTest::touch();
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        try {
            createElement('button', ['onClick' => $invokable]);
        } finally {
            self::assertSame(0, self::$invoked);
        }
    }

    public function testRT26EventPropRejectsScalars(): void
    {
        foreach ([1, 1.5, true, false, ''] as $value) {
            try {
                createElement('form', ['onSubmit' => $value]);
                self::fail(var_export($value, true));
            } catch (\InvalidArgumentException) {
                self::assertSame(0, self::$invoked);
            }
        }
    }

    /** RT-0.5、RT-2.6 型宣言のある H のメソッドでは TypeError で、関数は実行されない */
    public function testRT26TagMethodSignatureAlsoRefusesStrings(): void
    {
        foreach (['div', 'button'] as $tag) {
            try {
                H::{$tag}(onClick: self::class . '::touch');
                self::fail('Expected TypeError');
            } catch (\TypeError) {
                self::assertSame(0, self::$invoked);
            }
        }
    }

    public function testRT26TagWithoutOnClickParameterGoesThroughTheSameCheck(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::p(onClick: 'phpinfo');
    }

    public static function touch(): void
    {
        self::$invoked++;
    }
}
