<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\Renderable;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-3 の出力 */
final class RendererOutputTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function animationsOfHref(): iterable
    {
        yield 'set to' => [['attributeName' => 'href', 'to' => 'javascript:alert(1)']];
        yield 'values' => [['attributeName' => 'href', 'values' => 'javascript:alert(1)']];
        yield 'later value' => [['attributeName' => 'href', 'values' => "/ok; JAVA\tSCRIPT:alert(1)"]];
        yield 'from' => [['attributeName' => 'href', 'from' => 'javascript:alert(1)']];
        yield 'by' => [['attributeName' => 'href', 'by' => 'javascript:alert(1)']];
        yield 'xlink' => [['attributeName' => 'xlink:href', 'to' => 'javascript:alert(1)']];
        yield 'any prefix' => [['attributeName' => 'x:href', 'to' => 'javascript:alert(1)']];
        yield 'name case and space' => [['ATTRIBUTENAME' => ' HREF ', 'TO' => 'javascript:alert(1)']];
        yield 'data' => [['attributeName' => 'href', 'to' => 'data:text/html,x']];
    }

    /**
     * 01 の決定の記録の 8。RT-3.11 と同じ検査で、属性だけを落とす。
     *
     * @param array<string, string> $attrs
     */
    #[DataProvider('animationsOfHref')]
    public function testRT311AnimationOfHrefDropsDangerousValues(array $attrs): void
    {
        foreach (['set', 'animate', 'SET'] as $tag) {
            $html = self::html(static fn() => H::{$tag}(...$attrs));
            self::assertStringNotContainsStringIgnoringCase('script:', $html);
            self::assertStringNotContainsString('data:', $html);
            self::assertStringContainsStringIgnoringCase('attributeName=', $html);
        }
    }

    public function testRT311AnimationOfOtherAttributesAndSafeUrlsAreKept(): void
    {
        self::assertSame(
            '<set attributeName="title" to="javascript:x"></set>',
            self::html(static fn() => H::set(attributeName: 'title', to: 'javascript:x')),
        );
        self::assertSame(
            '<animate attributeName="href" values="/a;/b" from="/a" to="#c"></animate>',
            self::html(static fn() => H::animate(attributeName: 'href', values: '/a;/b', from: '/a', to: '#c')),
        );
    }

    public function testRT31EscapesTextAndAttributes(): void
    {
        self::assertSame(
            '<a title="&lt;&quot;&#039;&amp;&gt;">&lt;b&gt;&quot;&#039;&amp;</a>',
            self::html(static fn() => H::a(title: '<"\'&>', children: '<b>"\'&')),
        );
    }

    public function testRT31InvalidUtf8TextIsSubstitutedNotEmptied(): void
    {
        self::assertStringContainsString('ok', self::html(static fn() => H::p(children: "ok\xFF")));
    }

    public function testRT31InvalidUtf8AttributeIsSubstitutedNotEmptied(): void
    {
        self::assertStringContainsString('ok', self::html(static fn() => H::p(title: "ok\xFF")));
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

    public function testRT32NestedArraysAreFlattenedInOrder(): void
    {
        self::assertSame('<p>abc</p>', self::html(static fn() => new Element('p', [], ['a', ['b', ['c']]])));
    }

    public function testRT32MixedChildren(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return '<s>';
            }
        };
        $renderable = new class implements Renderable {
            public function render(): Element
            {
                return H::span(children: 'r');
            }
        };

        self::assertSame(
            '<p>1.5<i></i>7&lt;s&gt;<span>r</span></p>',
            self::html(static fn() => new Element('p', [], [1.5, new Element('i'), null, true, false, 7, $stringable, $renderable])),
        );
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unrenderable(): iterable
    {
        yield 'object' => [new \stdClass(), 'stdClass'];
        yield 'closure' => [static fn() => 'x', 'Closure'];
        yield 'resource' => [fopen('php://memory', 'r'), 'resource'];
    }

    #[DataProvider('unrenderable')]
    public function testRT32UnrenderableChildThrowsWithTheTypeName(mixed $child, string $type): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($type);
        self::html(static fn() => new Element('p', [], [$child]));
    }

    public function testRT33DirectStringIsEscaped(): void
    {
        self::assertSame('&lt;x&gt;', self::html(static fn() => '<x>'));
    }

    /** @return iterable<string, array{string}> */
    public static function badTags(): iterable
    {
        yield 'space' => ['div onclick=x'];
        yield 'angle' => ['a><script>'];
        yield 'empty' => [''];
        yield 'digit' => ['1a'];
        yield 'newline' => ["div\n"];
    }

    #[DataProvider('badTags')]
    public function testRT34InvalidTagNameThrows(string $tag): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => new Element($tag));
    }

    public function testRT34SvgAndCustomElementNamesPass(): void
    {
        self::assertSame('<linearGradient></linearGradient><my-widget></my-widget>', self::html(static fn() => H::Fragment([new Element('linearGradient'), new Element('my-widget')])));
    }

    public function testRT35VoidElements(): void
    {
        foreach (['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'] as $tag) {
            self::assertSame("<{$tag} />", self::html(static fn() => new Element($tag, [], ['ignored'])));
        }
    }

    public function testRT35VoidIsCaseInsensitive(): void
    {
        self::assertSame('<BR />', self::html(static fn() => new Element('BR')));
    }

    public function testRT37JsxNamesAreMapped(): void
    {
        self::assertSame('<div class="c" for="f" tabindex="1" stroke-width="2" xlink:href="/x" unknownName="u"></div>', self::html(static fn() => new Element('div', [
            'className' => 'c', 'htmlFor' => 'f', 'tabIndex' => 1, 'strokeWidth' => 2, 'xlinkHref' => '/x', 'unknownName' => 'u',
        ])));
    }

    public function testRT37EveryMappedNamePassesTheAttributeGrammar(): void
    {
        foreach (\Polidog\Rosanna\Runtime\AttributeNames::MAP as $name) {
            self::assertMatchesRegularExpression(\Polidog\Rosanna\Runtime\Renderer::ATTRIBUTE_NAME_PATTERN, $name);
        }
    }

    public function testRT39BooleanStringAttributes(): void
    {
        self::assertSame(
            '<div aria-hidden="true" aria-busy="false" contenteditable="true" draggable="false" spellcheck="true"></div>',
            self::html(static fn() => new Element('div', ['aria-hidden' => true, 'aria-busy' => false, 'contentEditable' => true, 'draggable' => false, 'spellCheck' => true])),
        );
    }

    public function testRT39OtherBooleansArePresenceOnly(): void
    {
        self::assertSame('<input disabled />', self::html(static fn() => H::input(disabled: true, checked: false)));
    }

    public function testRT39NonScalarIsDroppedWithANotice(): void
    {
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = [$level, $message];

            return true;
        });
        try {
            $html = self::html(static fn() => new Element('div', ['title' => ['x'], 'id' => 'y']));
        } finally {
            restore_error_handler();
        }

        self::assertSame('<div id="y"></div>', $html);
        self::assertCount(1, $notices);
        self::assertSame(E_USER_NOTICE, $notices[0][0]);
        self::assertStringContainsString('title', $notices[0][1]);
        self::assertStringContainsString('array', $notices[0][1]);
    }

    public function testRT310SrcdocWithValueIsRefused(): void
    {
        foreach (['srcdoc', 'srcDoc', 'SRCDOC'] as $prop) {
            try {
                self::html(static fn() => new Element('iframe', [$prop => '<p>x</p>']));
                self::fail($prop);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('srcdoc', $e->getMessage());
            }
        }
    }

    public function testRT310EmptySrcdocIsAllowed(): void
    {
        self::assertSame('<iframe srcdoc=""></iframe>', self::html(static fn() => H::iframe(srcdoc: '')));
    }

    public function testRT313NonTextInRawTextIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only text');
        self::html(static fn() => new Element('style', [], [new Element('b')]));
    }

    public function testRT312AllowedRawTextChildren(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 's';
            }
        };
        self::assertSame('<style>a<b>1s2</style>', self::html(static fn() => new Element('style', [], ['a<b>', 1, $stringable, null, true, [2]])));
    }

    public function testRT312OtherRawEndTagIsHarmless(): void
    {
        self::assertSame('<style></script></style>', self::html(static fn() => H::style(children: '</script>')));
    }
}
