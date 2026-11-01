<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * README の `<!-- readme-test: 名前 -->` を付けたコードをそのままファイルに書き、実際の PHP で動かす (01 の 12 節のテスト)。
 * README の php のコードはすべて印を持ち、英語版と日本語版で同じであることもここで確かめる。
 */
final class ReadmeExamplesTest extends TestCase
{
    private const SECRET = 'readme-test-secret';

    private const EXAMPLES = [
        'quickstart', 'keys', 'effect', 'form-input', 'elements', 'class-component', 'storage', 'snapshot-behavior',
        'psx-counter', 'psx-host', 'guard', 'worker', 'defer', 'defer-entry',
    ];

    /** @var list<resource> */
    private array $servers = [];

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        foreach ($this->directories as $directory) {
            self::remove($directory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function readmes(): iterable
    {
        yield 'README.md' => ['README.md'];
        yield 'README.ja.md' => ['README.ja.md'];
    }

    #[DataProvider('readmes')]
    public function testEveryPhpBlockIsMarkedAndEveryExampleExists(string $file): void
    {
        $readme = self::readme($file);

        preg_match_all('/^```php$/m', $readme, $all);
        preg_match_all('/^<!-- readme-test: ([a-z-]+) -->\n```php$/m', $readme, $marked);

        self::assertCount(count($all[0]), $marked[1], 'Every php block needs a readme-test marker');
        self::assertSame(self::EXAMPLES, $marked[1]);
    }

    public function testBothReadmesShowTheSameCode(): void
    {
        foreach (self::EXAMPLES as $name) {
            self::assertSame(self::block($name, 'README.md'), self::block($name, 'README.ja.md'), $name);
        }
    }

    /** 仕様が「ドキュメントに書く」とした項目が消えないようにする */
    #[DataProvider('readmes')]
    public function testDocumentedRequirementsArePresent(string $file): void
    {
        $readme = self::readme($file);

        foreach ([
            'TOTO',                               // 計画書: 名前の由来
            'RequestLifecycle::reset()',          // RT-9.6
            'RewriteRule',                        // 02 の 8 節
            'session_write_close',                // VR-4 のロックしないハンドラー
            'sessionStorage',                     // VR-6
            'rosanna:stale',                      // JS-8a
            'rosanna:submit-error',               // JS-5
            'trustProxyHeaders',                  // CS-7
            'X-Rosanna-Stale',                    // VR-4
            'X-Rosanna-Session-Started',          // HD-16
            'SnapshotBehavior::Persistent',       // 2.5
            'composer remove polidog/use-php',    // 計画書: 移行手順
            'data-rosanna-reload-defer',          // JS-20
            '/_defer/{name}',                     // DF-12
            'onSubmit',                           // AC-19
            'localStorage',                       // JS-17
            'composer install --no-dev',          // 計画書: --no-dev
            '`/admin/`',                          // SG-5 と host の保護の差
        ] as $needle) {
            self::assertStringContainsString($needle, $readme);
        }
    }

    public function testQuickstartWorksWithoutAndWithJavaScript(): void
    {
        $port = $this->serve($this->project(['public/index.php' => self::block('quickstart')]));

        $page = self::http($port, '/');
        self::assertStringContainsString('Count: 0', $page['body']);
        self::assertStringContainsString('<script src="/rosanna.js" defer', $page['body']);
        self::assertNull(self::cookieOf($page), 'Reading state must not start a session');

        $posted = self::post($port, '/', self::buttonFields($page['body'], 0));
        self::assertSame(303, $posted['status']);
        $cookie = self::cookieOf($posted);
        self::assertNotNull($cookie);

        $page = self::http($port, '/', ['Cookie' => $cookie]);
        self::assertStringContainsString('Count: 1', $page['body']);

        $partial = self::post($port, '/', self::buttonFields($page['body'], 0), ['Cookie' => $cookie, 'X-Rosanna-Partial' => '1']);
        self::assertSame(200, $partial['status']);
        self::assertStringContainsString('Count: 2', $partial['body']);
        self::assertStringNotContainsString('<html', $partial['body']);
    }

    public function testClassComponentEmbeddingWorks(): void
    {
        $port = $this->serve($this->project(['public/index.php' => self::block('class-component')]));

        $page = self::http($port, '/');
        self::assertStringContainsString('Count: 0', $page['body']);
        // 秘密鍵がないので署名の鍵のためにセッションが始まる (SG-2)
        $cookie = self::cookieOf($page);
        self::assertNotNull($cookie);

        $posted = self::post($port, '/', self::buttonFields($page['body'], 0), ['Cookie' => $cookie]);
        self::assertSame(303, $posted['status']);
        self::assertStringContainsString('Count: 1', self::http($port, '/', ['Cookie' => $cookie])['body']);
    }

    public function testTheThreeStorageTypes(): void
    {
        $port = $this->serve($this->project(['public/index.php' => self::block('storage')]));
        $page = self::http($port, '/');

        self::assertNull(self::cookieOf($page));
        self::assertStringContainsString('data-rosanna-persist', $page['body']);
        self::assertStringContainsString('data-rosanna-snapshot', $page['body']);

        // Session: JavaScript なしでも redirect の後に残る
        $session = self::post($port, '/', self::buttonFields($page['body'], 0));
        self::assertSame(303, $session['status']);
        $cookie = (string) self::cookieOf($session);
        self::assertStringContainsString('session: 1', self::http($port, '/', ['Cookie' => $cookie])['body']);

        // Memory: 部分更新でだけ変わり、redirect の先では初期値に戻る
        $fresh = self::http($port, '/');
        $memory = self::post($port, '/', self::buttonFields($fresh['body'], 1), ['X-Rosanna-Partial' => '1']);
        self::assertStringContainsString('memory: 1', $memory['body']);
        $plain = self::post($port, '/', self::buttonFields($fresh['body'], 1));
        self::assertSame(303, $plain['status']);
        self::assertStringContainsString('memory: 0', self::http($port, '/')['body']);

        // Snapshot: JavaScript なしは POST に全ページで答える
        $snapshot = self::post($port, '/', self::buttonFields($fresh['body'], 2));
        self::assertSame(200, $snapshot['status']);
        self::assertStringContainsString('snapshot: 1', $snapshot['body']);
        self::assertSame('no-store', self::header($snapshot, 'Cache-Control'));
        self::assertStringContainsString('snapshot: 0', self::http($port, '/')['body'], 'A reload goes back to the initial value');
    }

    public function testPersistentSnapshotTravelsInTheUrl(): void
    {
        $port = $this->serve($this->project(['public/index.php' => self::block('snapshot-behavior')]));
        $page = self::http($port, '/?tab=1');

        $posted = self::post($port, '/?tab=1', self::buttonFields($page['body'], 0));
        self::assertSame(303, $posted['status']);
        $location = (string) self::header($posted, 'Location');
        self::assertStringStartsWith('/?tab=1&_rosanna_snapshot=', $location);

        self::assertStringContainsString('Items: 1', self::http($port, $location)['body']);
    }

    public function testFormInputIsCopiedIntoTheHook(): void
    {
        $port = $this->serve($this->project(['public/index.php' => self::block('form-input')]));
        $page = self::http($port, '/');

        $posted = self::post($port, '/', ['name' => 'Ann'] + self::formFields($page['body']));
        self::assertSame(303, $posted['status']);

        $next = self::http($port, '/', ['Cookie' => (string) self::cookieOf($posted)]);
        self::assertStringContainsString('Saved: Ann', $next['body']);
    }

    public function testKeysSeparateTheInstances(): void
    {
        $port = $this->serve($this->project(['public/index.php' => self::block('keys')]));
        $page = self::http($port, '/');

        $posted = self::post($port, '/', self::buttonFields($page['body'], 1));
        $next = self::http($port, '/', ['Cookie' => (string) self::cookieOf($posted)]);

        self::assertStringContainsString('first: 0', $next['body']);
        self::assertStringContainsString('second: 1', $next['body']);
    }

    public function testArrayDepsStartASessionAndNullDepsDoNot(): void
    {
        $block = self::block('effect');
        $withDeps = $this->serve($this->project(['public/index.php' => $block]));
        self::assertNotNull(self::cookieOf(self::http($withDeps, '/')));

        $emptyDeps = str_replace('}, [$props[\'id\']]);', '}, []);', $block);
        self::assertNotNull(self::cookieOf(self::http($this->serve($this->project(['public/index.php' => $emptyDeps])), '/')));

        $withoutDeps = str_replace('}, [$props[\'id\']]);', '});', $block, $count);
        self::assertSame(1, $count);
        $port = $this->serve($this->project(['public/index.php' => $withoutDeps]));
        $page = self::http($port, '/');
        self::assertStringContainsString('Article 1', $page['body']);
        self::assertNull(self::cookieOf($page));
    }

    public function testElementsRenderAsDocumented(): void
    {
        $directory = $this->project(['example.php' => self::block('elements')]);

        self::assertSame(
            '<div class="card"><h2>Title</h2><ul><li>one</li><li>two</li></ul>'
            . '<button aria-label="Close" data-id="1">x</button><textarea name="body">text</textarea></div>',
            self::execute([PHP_BINARY, 'example.php'], $directory)['output'],
        );
    }

    public function testPsxExampleCompilesAndRuns(): void
    {
        $directory = $this->project([
            'components/Counter.psx' => self::block('psx-counter'),
            'public/index.php' => self::block('psx-host'),
        ]);

        $compile = self::execute([PHP_BINARY, dirname(__DIR__, 2) . '/bin/rosanna', 'compile', 'components'], $directory);
        self::assertSame(0, $compile['code'], $compile['output']);
        self::assertSame(0, self::execute([PHP_BINARY, dirname(__DIR__, 2) . '/bin/rosanna', 'compile', '--check', 'components'], $directory)['code']);

        $port = $this->serve($directory);
        $page = self::http($port, '/');
        self::assertStringContainsString('Count: 5', $page['body']);

        $posted = self::post($port, '/', self::buttonFields($page['body'], 0));
        self::assertSame(303, $posted['status']);
        self::assertStringContainsString('Count: 6', self::http($port, '/', ['Cookie' => (string) self::cookieOf($posted)])['body']);
    }

    /** SG-5 は末尾の `/` を同じページとして扱うので、host の判定も同じ単位でなければ避けられる */
    public function testGuardCoversEveryPathTheSignatureAccepts(): void
    {
        $port = $this->serve($this->project(['public/index.php' => self::block('guard')]));
        $page = self::http($port, '/admin', ['Cookie' => 'role=admin']);
        self::assertSame(200, $page['status']);
        $fields = self::buttonFields($page['body'], 0);

        foreach (['/admin', '/admin/', '/admin//', '//admin'] as $path) {
            self::assertSame(403, self::post($port, $path, $fields)['status'], $path);
        }

        self::assertSame(400, self::post($port, '/other', $fields)['status'], 'A form is bound to its page');
        self::assertSame(303, self::post($port, '/admin', $fields, ['Cookie' => 'role=admin'])['status']);
    }

    /** SG-5 README の保護の例は Request::pagePath() で比べる。生の path の比較を勧めない */
    public function testSG5GuardExampleUsesThePagePath(): void
    {
        foreach (['README.md', 'README.ja.md'] as $file) {
            $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

            self::assertStringContainsString("\$request->pagePath() === '/admin'", $readme, $file);
            self::assertStringNotContainsString("\$pagePath = '/' . trim(\$request->path, '/');", $readme, $file);
        }
    }

    /** README の既知の制約と host の注意 (02 の決定) */
    public function testKnownLimitationsFromTheReviewAreDocumented(): void
    {
        foreach ([
            'README.md' => ['`X-Rosanna-Session-Started: 1`', 'The refresh costs one more GET', 'Forms outside wrappers that the host built in JS are not renewed', 'Without JavaScript, the plain POST', 'A plain POST without `X-Rosanna-Partial` skips this check', '`Request::$cookies` and `$_COOKIE`', 'can write an unbound value into your own slot'],
            'README.ja.md' => ['`X-Rosanna-Session-Started: 1`', '更新の分の GET が 1 回増えます', 'host が JS で組み立てたラッパーの外のフォームは新しくなりません', 'JavaScript なしでは、通常の POST の応答で', '`X-Rosanna-Partial` を外した通常の POST ではこの確認を通りません', '`Request::$cookies` と `$_COOKIE`', 'セッションに束縛されていない値を自分のスロットに書けます'],
        ] as $file => $needles) {
            $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $readme, $file);
            }
            self::assertStringNotContainsString('are not inspected', $readme, $file);
            self::assertStringNotContainsString('`to`、`values` は検査しません', $readme, $file);
        }
    }

    public function testDeferredComponentsAreFetchedFromTheirEndpoints(): void
    {
        $port = $this->serve($this->project(['public/index.php' => self::block('defer')]));
        $page = self::http($port, '/');

        self::assertStringContainsString('data-rosanna-defer-url="/_defer/announcement-bar"', $page['body']);
        self::assertStringContainsString('data-rosanna-defer-url="/_defer/user-header?name=Ann"', $page['body']);
        self::assertStringContainsString('data-rosanna-defer-cache-ttl="60"', $page['body']);
        self::assertStringContainsString('data-rosanna-defer-name="user-header"', $page['body']);
        self::assertStringContainsString('Loading', $page['body']);
        self::assertStringNotContainsString('Hello', $page['body']);

        $bar = self::http($port, '/_defer/announcement-bar');
        self::assertStringContainsString('Sale today', $bar['body']);
        self::assertSame('public, s-maxage=60', self::header($bar, 'Cache-Control'));

        $header = self::http($port, '/_defer/user-header?name=Ann&key=victim');
        self::assertStringContainsString('Hello Ann', $header['body']);
        self::assertSame('private, no-store', self::header($header, 'Cache-Control'));

        self::assertSame(404, self::http($port, '/_defer/unknown')['status']);
    }

    /** .htaccess が書き換えた先のファイルと同じ形。組み込みサーバーのルーターで代わりに動かす */
    public function testDeferEntryFileAnswersOnlyItsPrefix(): void
    {
        $directory = $this->project(['public/defer.php' => self::block('defer-entry')]);
        $port = $this->serve($directory, 'public/defer.php');

        $response = self::http($port, '/_defer/notice');
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Notice', $response['body']);
        self::assertSame(404, self::http($port, '/other')['status']);
    }

    public function testWorkerLoopResetsBetweenRequests(): void
    {
        $directory = $this->project([
            'worker.php' => self::block('worker'),
            'stub.php' => <<<'PHP'
                <?php
                function frankenphp_handle_request(callable $handler): bool
                {
                    static $requests = 0;
                    if ($requests > 0) {
                        echo Polidog\Rosanna\Runtime\ComponentState::current() === null && Polidog\Rosanna\Runtime\RenderContext::currentScope() === null ? "clean\n" : "dirty\n";
                    }
                    $handler();
                    echo "\n";

                    return ++$requests < 2;
                }
                PHP,
        ]);

        $result = self::execute([PHP_BINARY, '-d', 'auto_prepend_file=' . $directory . '/stub.php', 'worker.php'], $directory);

        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString("clean\n", $result['output']);
        self::assertSame(2, substr_count($result['output'], 'Count: 0'));
    }

    /** README の「出力の後に描画しない」の節の 3 つの症状 */
    public function testRenderingAfterOutputBehavesAsDocumented(): void
    {
        $port = $this->serve($this->project(['public/index.php' => <<<'PHP'
            <?php
            declare(strict_types=1);

            use Polidog\Rosanna\Html\H;
            use Polidog\Rosanna\Http\Request;
            use Polidog\Rosanna\Rosanna;
            use Polidog\Rosanna\Runtime\Element;
            use Polidog\Rosanna\Runtime\RenderContext;

            use function Polidog\Rosanna\Runtime\fc;
            use function Polidog\Rosanna\Runtime\useState;

            require __DIR__ . '/../vendor/autoload.php';

            $Counter = fc(function (): Element {
                [$count, $setCount] = useState(0);

                return H::button(onClick: fn() => $setCount($count + 1), children: "Count: {$count}");
            }, key: 'counter');

            $app = new Rosanna();
            $request = Request::fromGlobals();
            if ($request->path !== '/no-secret') {
                $app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
            }

            $response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $Counter());
            if ($response !== null) {
                $response->send();

                return;
            }

            if ($request->path !== '/') {
                echo 'early';
                flush();
            }

            echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Counter()));
            PHP]));

        $posted = self::post($port, '/', self::buttonFields(self::http($port, '/')['body'], 0));
        $cookie = (string) self::cookieOf($posted);
        self::assertStringContainsString('Count: 1', self::http($port, '/', ['Cookie' => $cookie])['body']);

        // Session の状態が初期値に見える
        $late = self::http($port, '/late', ['Cookie' => $cookie]);
        self::assertStringContainsString('Count: 0', $late['body']);

        // CSRF トークンがないフォームは Cookie を持つ訪問者には 403
        $fields = self::buttonFields($late['body'], 0);
        self::assertArrayNotHasKey('_rosanna_csrf', $fields);
        self::assertSame(403, self::post($port, '/late', $fields, ['Cookie' => $cookie])['status']);

        // 秘密鍵がなければ署名できず 400
        $noSecret = self::http($port, '/no-secret');
        self::assertSame(400, self::post($port, '/no-secret', self::buttonFields($noSecret['body'], 0))['status']);
    }

    private static function readme(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function block(string $name, string $file = 'README.md'): string
    {
        $found = preg_match('/^<!-- readme-test: ' . preg_quote($name, '/') . ' -->\n```php\n(.*?)^```$/ms', self::readme($file), $matches);
        self::assertSame(1, $found, "{$file} has no example named {$name}");

        return $matches[1];
    }

    /**
     * @param array<string, string> $files
     */
    private function project(array $files): string
    {
        $directory = sys_get_temp_dir() . '/rosanna-readme-' . bin2hex(random_bytes(6));
        mkdir($directory . '/vendor', 0o777, true);
        mkdir($directory . '/sessions');
        file_put_contents($directory . '/vendor/autoload.php', '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($directory . '/' . $path))) {
                mkdir(dirname($directory . '/' . $path), 0o777, true);
            }
            file_put_contents($directory . '/' . $path, $content);
        }
        $this->directories[] = $directory;

        return $directory;
    }

    private function serve(string $directory, ?string $router = null): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        $command = [
            PHP_BINARY, '-n',
            '-d', 'session.save_path=' . $directory . '/sessions',
            '-d', 'output_buffering=0',
            '-d', 'display_errors=0',
            '-S', '127.0.0.1:' . $port,
            '-t', 'public',
        ];
        if ($router !== null) {
            $command[] = $router;
        }

        $server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $directory, ['ROSANNA_SNAPSHOT_SECRET' => self::SECRET]);
        self::assertIsResource($server);
        $this->servers[] = $server;

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
            usleep(50_000);
        }
        self::fail('The built-in server did not start');
    }

    /**
     * @param list<string> $command
     * @return array{code: int, output: string}
     */
    private static function execute(array $command, string $directory): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, ['ROSANNA_SNAPSHOT_SECRET' => self::SECRET]);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'output' => $output];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function http(int $port, string $target, array $headers = [], string $method = 'GET', string $body = ''): array
    {
        $socket = fsockopen('127.0.0.1', $port, $errno, $errstr, 5.0);
        self::assertNotFalse($socket, $errstr);

        $request = "{$method} {$target} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }
        if ($method === 'POST') {
            $request .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n";
        }
        fwrite($socket, $request . "\r\n" . $body);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        $statusLine = (string) array_shift($lines);

        return ['status' => (int) (explode(' ', $statusLine)[1] ?? 0), 'headers' => $lines, 'body' => $responseBody];
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function post(int $port, string $target, array $fields, array $headers = []): array
    {
        return self::http($port, $target, $headers + ['Origin' => 'http://127.0.0.1:' . $port], 'POST', http_build_query($fields));
    }

    /** @param array{headers: list<string>} $response */
    private static function header(array $response, string $name): ?string
    {
        foreach ($response['headers'] as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }

    /** @param array{headers: list<string>} $response */
    private static function cookieOf(array $response): ?string
    {
        $value = self::header($response, 'Set-Cookie');

        return $value === null ? null : trim(explode(';', $value)[0]);
    }

    private static function attribute(string $tag, string $name): ?string
    {
        return preg_match('/\s' . preg_quote($name, '/') . '="([^"]*)"/', $tag, $matches) === 1
            ? html_entity_decode($matches[1], ENT_QUOTES)
            : null;
    }

    /**
     * JavaScript なしのブラウザが n 番目の action のボタンで送る内容。
     *
     * @return array<string, string>
     */
    private static function buttonFields(string $html, int $index): array
    {
        preg_match_all('/<button\b[^>]*name="_rosanna_action"[^>]*>/', $html, $buttons);
        $button = $buttons[0][$index] ?? null;
        self::assertNotNull($button, "No action button #{$index} in: {$html}");

        $found = preg_match('/<form\b[^>]*\bid="' . preg_quote((string) self::attribute($button, 'form'), '/') . '"[^>]*>(.*?)<\/form>/s', $html, $form);
        self::assertSame(1, $found);

        return ['_rosanna_action' => (string) self::attribute($button, 'value')] + self::inputs($form[1]);
    }

    /**
     * @return array<string, string>
     */
    private static function formFields(string $html): array
    {
        $found = preg_match('/<form\b[^>]*data-rosanna-form[^>]*>(.*?)<\/form>/s', $html, $form);
        self::assertSame(1, $found);

        return self::inputs($form[1]);
    }

    /** @return array<string, string> */
    private static function inputs(string $html): array
    {
        preg_match_all('/<input\b[^>]*>/', $html, $inputs);
        $fields = [];
        foreach ($inputs[0] as $input) {
            $name = self::attribute($input, 'name');
            if ($name !== null) {
                $fields[$name] = (string) self::attribute($input, 'value');
            }
        }

        return $fields;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
