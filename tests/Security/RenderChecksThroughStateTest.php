<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Security;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\State\RosannaState;

/**
 * RT-3 の検査は本体のテストで固定している。状態層の `RenderExtension` を通しても効くことをここで確かめる。
 */
final class RenderChecksThroughStateTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    private static function render(Element $element): string
    {
        return new RosannaState()->renderElement($element);
    }

    public function testRT31TextAndAttributesAreEscaped(): void
    {
        self::assertSame('<p title="&quot;">&lt;b&gt;</p>', self::render(H::p(title: '"', children: '<b>')));
    }

    public function testRT34InvalidTagIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML tag name');
        self::render(new Element('b"ad'));
    }

    public function testRT38InvalidAttributeNameIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name');
        self::render(new Element('div', ['a" onclick="x' => '1']));
    }

    public function testRT38InlineHandlerIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inline event handler');
        self::render(new Element('div', ['onclick' => 'x()']));
    }

    public function testRT310SrcdocIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('srcdoc');
        self::render(new Element('iframe', ['srcdoc' => '<script>x</script>']));
    }

    public function testRT311DangerousSchemeIsDropped(): void
    {
        self::assertSame('<a id="i"></a>', self::render(new Element('a', ['id' => 'i', 'href' => "java\tscript:alert(1)"])));
    }

    public function testRT312RawTextCannotCloseItself(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not contain');
        self::render(new Element('script', [], ['</', 'script>']));
    }

    public function testRT312RawTextCannotCloseItsAncestor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('</noscript');
        self::render(new Element('noscript', [], [new Element('style', [], ['</noscript>'])]));
    }

    public function testRT314ForeignContentEscapesRawText(): void
    {
        self::assertSame('<svg><style>&lt;img&gt;</style></svg>', self::render(new Element('svg', [], [new Element('style', [], ['<img>'])])));
    }

    public function testRT36UnsupportedChildIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        self::render(new Element('div', [], [new \stdClass()]));
    }
}
