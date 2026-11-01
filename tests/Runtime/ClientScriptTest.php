<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Rosanna;

/** RT-12.6、02 の 9 節の renderClientScript */
final class ClientScriptTest extends TestCase
{
    public function testExternalScriptIsDeferred(): void
    {
        self::assertStringContainsString('<script src="/rosanna.js" defer></script>', Rosanna::renderClientScript());
    }

    public function testSrcIsEscaped(): void
    {
        $html = Rosanna::renderClientScript('/a.js?v=1&x="y"');

        self::assertStringContainsString('src="/a.js?v=1&amp;x=&quot;y&quot;"', $html);
    }

    public function testFallbackOnlyTouchesDeferPlaceholders(): void
    {
        $html = Rosanna::renderClientScript();

        self::assertStringContainsString('data-rosanna-defer-url', $html);
        self::assertStringContainsString('data-rosanna-defer-fallback-loading', $html);
        self::assertStringContainsString('X-Rosanna-Defer', $html);
        self::assertStringContainsString('window.Rosanna.reloadDefer', $html);
        // JS-16 の条件
        self::assertStringContainsString('u.origin===location.origin', $html);
        self::assertStringContainsString("indexOf('text/html')!==0", $html);
        // JS-17 localStorage に書かない。フォームの部分更新もしない
        self::assertStringNotContainsString('localStorage', $html);
        self::assertStringNotContainsString('data-rosanna-form', $html);
    }

    public function testNoPrefixScriptByDefault(): void
    {
        self::assertStringNotContainsString('window.Rosanna.deferPrefix=', Rosanna::renderClientScript());
    }

    public function testDeferPrefixIsPassedAsAJsString(): void
    {
        self::assertStringContainsString('window.Rosanna.deferPrefix="/api/_d";', Rosanna::renderClientScript('/rosanna.js', '/api/_d'));
    }

    public function testDeferPrefixCannotCloseTheScript(): void
    {
        $html = Rosanna::renderClientScript('/rosanna.js', '/x</script><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('\\u003C/script\\u003E', $html);
    }

    public function testRT126NonceIsOnEveryScript(): void
    {
        $html = Rosanna::renderClientScript('/rosanna.js', '/_d', 'abc"123');

        self::assertSame(3, substr_count($html, '<script'));
        self::assertSame(3, substr_count($html, ' nonce="abc&quot;123"'));
        // CSP でインラインのハンドラは動かないので使わない
        self::assertStringNotContainsString('onerror', $html);
    }

    public function testNoNonceAttributeWithoutANonce(): void
    {
        self::assertStringNotContainsString('nonce', Rosanna::renderClientScript());
    }

    /** node があれば構文を確かめる。ブラウザでの動作は手順の確認に記録する */
    public function testInlineScriptParses(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not available');
        }
        preg_match('/<script>(.*?)<\/script>/s', Rosanna::renderClientScript(), $match);
        $file = (string) tempnam(sys_get_temp_dir(), 'rosanna-inline-') . '.js';
        file_put_contents($file, $match[1]);

        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        unlink($file);

        self::assertSame(0, $code, implode("\n", $output));
    }
}
