<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Psx\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\FunctionComponent;

/**
 * §8: コンパイルから描画まで。コンポーネントのタグ、manifest、PsxArgumentResolver。
 */
#[Group('integration')]
final class ComponentRenderingTest extends TestCase
{
    use RendersPsx;

    /** §8 のテスト、RT-11.3: `fc()` の内側にも効く */
    public function testArgumentResolverAppliesInsideFc(): void
    {
        $app = $this->buildApp([
            'Foo' => "<?php\nnamespace App;\nuse function Polidog\\Rosanna\\State\\fc;\nreturn fc(fn (array \$props, \\ArrayObject \$repo) => <i>{\$repo['v']}{\$props['p']}</i>, key: 'foo');\n",
            'Page' => "<?php\nnamespace App;\nreturn fn (array \$props) => <Foo p=\"!\" />;\n",
        ]);
        $app->setArgumentResolver(new RecordingResolver([new \ArrayObject(['v' => 'resolved'])]));

        self::assertStringContainsString('<i>resolved!</i>', $this->renderFqcn($app, 'App\\Page'));
    }

    /** CP-132: `fc()` で包んだファイルの返り値 */
    public function testFcFileReturnsFunctionComponent(): void
    {
        $this->buildApp([
            'Foo' => "<?php\nnamespace App;\nuse function Polidog\\Rosanna\\State\\fc;\nreturn fc(fn (array \$props) => <i />, key: 'foo');\n",
        ]);
        /** @var array<string, array{file: string}> $manifest */
        $manifest = require $this->cacheDir() . '/manifest.php';

        self::assertInstanceOf(FunctionComponent::class, require $manifest['App\\Foo']['file']);
    }

    private function renderFqcn(\Polidog\Rosanna\State\RosannaState $app, string $fqcn): string
    {
        $html = $this->inScope($app, static function () use ($app, $fqcn): string {
            return $app->renderElement(RenderContext::scope()->psx->render($fqcn, []));
        });
        \assert(\is_string($html));

        return $html;
    }
}
