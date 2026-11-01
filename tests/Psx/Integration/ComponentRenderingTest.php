<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\FunctionComponent;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\StackTraceRewriter;

/**
 * §8: コンパイルから描画まで。コンポーネントのタグ、manifest、PsxArgumentResolver。
 */
#[Group('integration')]
final class ComponentRenderingTest extends TestCase
{
    use RendersPsx;

    /** §8 のテスト: コンパイルから描画まで */
    public function testCompileThenRender(): void
    {
        $app = $this->buildApp([
            'Hello' => "<?php\nnamespace App;\nreturn fn (array \$props) => <b>Hello {\$props['name']}</b>;\n",
        ]);

        $html = $this->inScope($app, static fn(): string => $app->renderElement(
            RenderContext::scope()->psx->render('App\\Hello', ['name' => 'x']),
        ));

        self::assertSame('<b>Hello x</b>', $html);
    }

    /** CP-60、CP-90 */
    public function testNestedComponentTagsResolveAcrossFiles(): void
    {
        $app = $this->buildApp([
            'Inner' => "<?php\nnamespace App;\nreturn fn (array \$props) => <i>{\$props['v']}</i>;\n",
            'Outer' => "<?php\nnamespace App;\nreturn fn (array \$props) => <div><Inner v=\"1\" /><Inner v={2} /></div>;\n",
        ]);

        self::assertSame('<div><i>1</i><i>2</i></div>', $this->renderFqcn($app, 'App\\Outer'));
    }

    /** CP-72: コンポーネントは 1 つの子を配列でなく受け取る */
    public function testComponentReceivesASingleChildAsIs(): void
    {
        $app = $this->buildApp([
            'Kind' => "<?php\nnamespace App;\nreturn fn (array \$props) => <i>{get_debug_type(\$props['children'])}</i>;\n",
            'Text' => "<?php\nnamespace App;\nreturn fn (array \$props) => <Kind>x</Kind>;\n",
            'Elem' => "<?php\nnamespace App;\nreturn fn (array \$props) => <Kind><b /></Kind>;\n",
            'Many' => "<?php\nnamespace App;\nreturn fn (array \$props) => <Kind><b /><b /></Kind>;\n",
        ]);

        self::assertSame('<i>string</i>', $this->renderFqcn($app, 'App\\Text'));
        self::assertSame('<i>' . Element::class . '</i>', $this->renderFqcn($app, 'App\\Elem'));
        self::assertSame('<i>array</i>', $this->renderFqcn($app, 'App\\Many'));
    }

    /** CP-73: コンポーネントの `{...式}` は配列で届く */
    public function testComponentSpreadChildren(): void
    {
        $app = $this->buildApp([
            'Wrap' => "<?php\nnamespace App;\nreturn fn (array \$props) => <div>{\$props['children']}</div>;\n",
            'Page' => "<?php\nnamespace App;\nreturn fn (array \$props) => <Wrap>{...['a', 'b']}</Wrap>;\n",
        ]);

        self::assertSame('<div>ab</div>', $this->renderFqcn($app, 'App\\Page'));
    }

    /** §8: 既定の resolver は default と nullable を解決する (RT-11.4) */
    public function testDefaultResolverFillsDefaultsAndNulls(): void
    {
        $app = $this->buildApp([
            'Foo' => "<?php\nnamespace App;\nreturn fn (array \$props, int \$n = 7, ?int \$x = null) => <i>{\$n}{\$x ?? 'null'}</i>;\n",
        ]);

        self::assertSame('<i>7null</i>', $this->renderFqcn($app, 'App\\Foo'));
    }

    /** §8 のテスト、CP-134: `service` の引数が host の実装で解決される */
    public function testArgumentResolverResolvesServices(): void
    {
        $app = $this->buildApp([
            'Foo' => "<?php\nnamespace App;\nreturn fn (array \$props, \\ArrayObject \$repo) => <i>{\$repo['v']}</i>;\n",
        ]);
        $resolver = new RecordingResolver([new \ArrayObject(['v' => 'resolved'])]);
        $app->setArgumentResolver($resolver);

        self::assertSame('<i>resolved</i>', $this->renderFqcn($app, 'App\\Foo'));
        self::assertSame('App\\Foo', $resolver->calls[0]['fqcn']);
        self::assertSame(
            [
                ['kind' => 'props', 'name' => 'props'],
                ['kind' => 'service', 'name' => 'repo', 'service' => 'ArrayObject'],
            ],
            $resolver->calls[0]['metadata'],
        );
    }

    /** §8 のテスト、RT-11.3: `fc()` の内側にも効く */
    public function testArgumentResolverAppliesInsideFc(): void
    {
        $app = $this->buildApp([
            'Foo' => "<?php\nnamespace App;\nuse function Polidog\\Rosanna\\Runtime\\fc;\nreturn fc(fn (array \$props, \\ArrayObject \$repo) => <i>{\$repo['v']}{\$props['p']}</i>, key: 'foo');\n",
            'Page' => "<?php\nnamespace App;\nreturn fn (array \$props) => <Foo p=\"!\" />;\n",
        ]);
        $app->setArgumentResolver(new RecordingResolver([new \ArrayObject(['v' => 'resolved'])]));

        self::assertStringContainsString('<i>resolved!</i>', $this->renderFqcn($app, 'App\\Page'));
    }

    /** CP-132: `fc()` で包んだファイルの返り値 */
    public function testFcFileReturnsFunctionComponent(): void
    {
        $this->buildApp([
            'Foo' => "<?php\nnamespace App;\nuse function Polidog\\Rosanna\\Runtime\\fc;\nreturn fc(fn (array \$props) => <i />, key: 'foo');\n",
        ]);
        /** @var array<string, array{file: string}> $manifest */
        $manifest = require $this->cacheDir() . '/manifest.php';

        self::assertInstanceOf(FunctionComponent::class, require $manifest['App\\Foo']['file']);
    }

    /** §10、CP-115: 実際の成果物の例外を `.psx` のパスと行で読める */
    public function testStackTraceRewriterReadsTheMeta(): void
    {
        $this->buildApp([
            'Boom' => "<?php\nnamespace App;\n\nreturn fn (array \$props) => <div>\n  {throw new \\DomainException('boom')}\n</div>;\n",
        ]);
        $source = $this->workDir() . '/components/Boom.psx';
        $artifact = CompileCommand::cachePathFor($this->cacheDir(), $source);

        self::assertSame($source, StackTraceRewriter::rewriteFile($artifact));

        $component = require $artifact;
        \assert($component instanceof \Closure);
        try {
            $component([]);
            self::fail('Expected the exception');
        } catch (\DomainException $e) {
            self::assertStringContainsString("$source:5", StackTraceRewriter::formatException($e));
        }
    }

    private function renderFqcn(\Polidog\Rosanna\Rosanna $app, string $fqcn): string
    {
        $html = $this->inScope($app, static function () use ($app, $fqcn): string {
            return $app->renderElement(RenderContext::scope()->psx->render($fqcn, []));
        });
        \assert(\is_string($html));

        return $html;
    }
}
