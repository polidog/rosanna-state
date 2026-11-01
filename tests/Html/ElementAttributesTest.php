<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Html;

use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Html\createElement;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-2.1、RT-2.2、RT-2.3、RT-2.5 */
final class ElementAttributesTest extends TestCase
{
    use RendersInScope;

    private const TAGS = 'div span p h1 h2 h3 h4 h5 h6 a strong em small mark del ins sub sup code pre blockquote q abbr cite time kbd samp varElement br wbr hr
        ul ol li dl dt dd table thead tbody tfoot tr th td caption colgroup col form input textarea button select option optgroup label
        fieldset legend datalist output progress meter header footer nav main article section aside figure figcaption details summary
        dialog address img audio video source track picture canvas svg iframe embed object param map area link meta title base script
        noscript template slot';

    private const NO_CHILDREN = 'br wbr hr col input progress meter img source track embed param area link meta base';

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testRT21EveryTagMethodExistsAndCreatesItsElement(): void
    {
        $tags = preg_split('/\s+/', self::TAGS);
        self::assertIsArray($tags);
        self::assertCount(96, $tags);

        foreach ($tags as $tag) {
            self::assertTrue(method_exists(H::class, $tag), $tag);
            $expected = $tag === 'varElement' ? 'var' : $tag;
            self::assertSame($expected, H::{$tag}()->type);
        }
    }

    public function testRT22ChildlessMethodsDeclareNoChildrenParameter(): void
    {
        foreach (explode(' ', self::NO_CHILDREN) as $tag) {
            $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), new \ReflectionMethod(H::class, $tag)->getParameters());
            self::assertNotContains('children', $names, $tag);
        }
    }

    public function testRT23NamedAttributesOutsideTheSignaturePassThrough(): void
    {
        self::assertSame('<div title="t" role="alert"></div>', self::html(static fn() => H::div(title: 't', role: 'alert')));
    }

    public function testRT23SpreadAttributes(): void
    {
        self::assertSame(
            '<button aria-label="Close" data-id="1">x</button>',
            self::html(static fn() => H::button(...['aria-label' => 'Close', 'data-id' => '1'], children: 'x')),
        );
    }

    public function testRT23PositionalExtrasAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::br(null, null, null, 'x'); // @phpstan-ignore argument.type
    }

    public function testRT23CallStaticKeepsNamedAttributes(): void
    {
        $element = H::customTag(className: 'x', children: 'y');
        self::assertSame('customTag', $element->type);
        self::assertSame(['className' => 'x'], $element->props);
        self::assertSame(['y'], $element->children);
    }

    public function testRT23CallStaticRejectsPositionalArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::customTag('positional');
    }

    public function testRT25TextareaValueBecomesItsContent(): void
    {
        $element = H::textarea(name: 'n', value: 12);
        self::assertSame(['12'], $element->children);
        self::assertArrayNotHasKey('value', $element->props);
        self::assertSame('<textarea name="n">12</textarea>', self::html(static fn() => $element));
    }

    public function testRT25NonTextareaKeepsValue(): void
    {
        self::assertSame(1.5, H::input(value: 1.5)->props['value']);
    }

    public function testRT25NullParamsAreIgnored(): void
    {
        self::assertSame([], createElement('div', ['className' => null, 'children' => null])->props);
    }

    public function testRT27FragmentResolvesChildren(): void
    {
        $fragment = H::Fragment(['a', ['b', ['c']]]);
        self::assertSame('Fragment', $fragment->type);
        self::assertSame(['a', 'b', 'c'], $fragment->children);
        self::assertSame('abc', self::html(static fn() => $fragment));
    }
}
