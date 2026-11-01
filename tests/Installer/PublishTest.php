<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Installer;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Installer\AssetInstaller;

/** RT-13、CP-140〜142。一時ディレクトリに vendor/polidog/rosanna の配置を作って動かす */
final class PublishTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/rosanna-publish-' . bin2hex(random_bytes(6));
        mkdir($this->project);
    }

    protected function tearDown(): void
    {
        self::remove($this->project);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private static function repo(string $path = ''): string
    {
        return dirname(__DIR__, 2) . $path;
    }

    private function installPackage(bool $withScript = true): string
    {
        $package = $this->project . '/vendor/polidog/rosanna';
        mkdir($package . '/bin', 0o755, true);
        mkdir($package . '/public');
        copy(self::repo('/bin/rosanna'), $package . '/bin/rosanna');
        if ($withScript) {
            file_put_contents($package . '/public/rosanna.js', '// published');
        }

        return $package;
    }

    /**
     * @param list<string> $arguments
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function runBin(string $bin, array $arguments, ?string $cwd = null): array
    {
        $process = proc_open([PHP_BINARY, $bin, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testRT132PublishCreatesTheDirectoryAndCopies(): void
    {
        $package = $this->installPackage();

        $result = self::runBin($package . '/bin/rosanna', ['publish']);

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame('// published', file_get_contents($this->project . '/public/rosanna.js'));
        self::assertSame('0755', substr(sprintf('%o', fileperms($this->project . '/public')), -4));
    }

    public function testRT132PublishOverwritesAnExistingFile(): void
    {
        $package = $this->installPackage();
        mkdir($this->project . '/public');
        file_put_contents($this->project . '/public/rosanna.js', 'old');

        self::assertSame(0, self::runBin($package . '/bin/rosanna', ['publish'])['code']);
        self::assertSame('// published', file_get_contents($this->project . '/public/rosanna.js'));
    }

    public function testRT133PackageRootOnlyExplains(): void
    {
        mkdir($this->project . '/pkg/bin', 0o755, true);
        copy(self::repo('/bin/rosanna'), $this->project . '/pkg/bin/rosanna');

        $result = self::runBin($this->project . '/pkg/bin/rosanna', ['publish']);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('package root', $result['stdout']);
        self::assertFileDoesNotExist($this->project . '/public');
    }

    public function testRT134MissingSourceFails(): void
    {
        $package = $this->installPackage(false);

        $result = self::runBin($package . '/bin/rosanna', ['publish']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('source not found', $result['stderr']);
    }

    public function testRT134DirectoryThatCannotBeCreatedFails(): void
    {
        $package = $this->installPackage();
        file_put_contents($this->project . '/public', 'a file');

        $result = self::runBin($package . '/bin/rosanna', ['publish']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('could not create', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function testRT134CopyFailureFails(): void
    {
        $package = $this->installPackage();
        mkdir($this->project . '/public/rosanna.js', 0o755, true);

        $result = self::runBin($package . '/bin/rosanna', ['publish']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('could not copy', $result['stderr']);
    }

    private function event(string $vendorDir, BufferIO $io): Event
    {
        $config = new Config(false, $this->project);
        $config->merge(['config' => ['vendor-dir' => $vendorDir]]);
        $composer = new Composer();
        $composer->setConfig($config);

        return new Event('post-install-cmd', $composer, $io);
    }

    public function testRT135InstallerUsesTheVendorDirSetting(): void
    {
        $io = new BufferIO();

        AssetInstaller::publish($this->event($this->project . '/custom/libs', $io));

        self::assertFileEquals(self::repo('/public/rosanna.js'), $this->project . '/custom/public/rosanna.js');
        self::assertStringContainsString('Published rosanna.js', $io->getOutput());
    }

    public function testRT135InstallerFailuresAreWarnings(): void
    {
        $io = new BufferIO();
        file_put_contents($this->project . '/public', 'a file');

        AssetInstaller::publish($this->event($this->project . '/vendor', $io));

        self::assertStringContainsString('could not create', $io->getOutput());
    }

    public function testRT135InstallerCopyFailureIsAWarning(): void
    {
        $io = new BufferIO();
        mkdir($this->project . '/public/rosanna.js', 0o755, true);

        AssetInstaller::publish($this->event($this->project . '/vendor', $io));

        self::assertStringContainsString('could not copy', $io->getOutput());
    }

    /** RT-13.5 想定外の例外も `composer install` を止めない */
    public function testRT135InstallerSwallowsAnExceptionAsAWarning(): void
    {
        $io = new BufferIO();
        // 設定のない Composer は getConfig() で Error を投げる
        $event = new Event('post-install-cmd', new Composer(), $io);

        AssetInstaller::publish($event);

        self::assertStringContainsString('could not publish rosanna.js', $io->getOutput());
    }

    public function testRT136ComposerIsNotARuntimeDependency(): void
    {
        $composer = json_decode((string) file_get_contents(self::repo('/composer.json')), true);

        self::assertArrayNotHasKey('composer/composer', $composer['require']);
        self::assertArrayHasKey('composer/composer', $composer['require-dev']);
        self::assertContains('bin/rosanna', $composer['bin']);
    }

    public function testRT138TheScriptIsShipped(): void
    {
        self::assertFileExists(self::repo('/public/rosanna.js'));
        self::assertStringNotContainsString('public', (string) file_get_contents(self::repo('/.gitattributes')));
    }

    public function testRT139UnknownCommandShowsHelpAndFails(): void
    {
        $result = self::runBin(self::repo('/bin/rosanna'), ['nope']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('Usage:', $result['stdout']);
        self::assertStringContainsString('nope', $result['stderr']);
    }

    public function testHelpExitsZero(): void
    {
        foreach ([[], ['help']] as $arguments) {
            $result = self::runBin(self::repo('/bin/rosanna'), $arguments);

            self::assertSame(0, $result['code']);
            self::assertStringContainsString('Usage:', $result['stdout']);
        }
    }

    /** CP-142 ヘルプが実装のオプションと一致する */
    public function testCP142HelpListsTheCompileOptions(): void
    {
        $help = self::runBin(self::repo('/bin/rosanna'), ['help'])['stdout'];

        preg_match_all("/\\\$arg === '(--[a-z]+)'|str_starts_with\\(\\\$arg, '(--[a-z]+=)'\\)/", (string) file_get_contents(self::repo('/src/Psx/CompileCommand.php')), $matches);
        $options = array_filter([...$matches[1], ...$matches[2]]);
        self::assertNotEmpty($options);
        foreach ($options as $option) {
            self::assertStringContainsString($option, $help);
        }
    }

    /** RT-13.7、CP-140 依存として入った bin からそのままコンパイルできる */
    public function testCP140CompileRunsFromTheInstalledPackage(): void
    {
        $package = $this->installPackage();
        file_put_contents($this->project . '/vendor/autoload.php', '<?php require ' . var_export(self::repo('/vendor/autoload.php'), true) . ';');
        mkdir($this->project . '/components');
        file_put_contents($this->project . '/components/Hello.psx', "<?php\nnamespace App;\nreturn fn (array \$props) => <b>x</b>;\n");

        $result = self::runBin($package . '/bin/rosanna', ['compile'], $this->project);

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertFileExists($this->project . '/var/cache/psx/manifest.php');
    }

    /** CP-140 引数と exit code が渡る */
    public function testCP140CompilePassesArgumentsAndTheExitCode(): void
    {
        $cwd = $this->project;
        mkdir($cwd . '/components');
        file_put_contents($cwd . '/components/Hello.psx', "<?php\nnamespace App;\nreturn fn (array \$props) => <b>x</b>;\n");
        $bin = self::repo('/bin/rosanna');

        $check = self::runBin($bin, ['compile', '--check', '--cache=' . $cwd . '/cache', 'components'], $cwd);
        self::assertSame(1, $check['code'], $check['stdout'] . $check['stderr']);

        $compile = self::runBin($bin, ['compile', '--cache=' . $cwd . '/cache', 'components'], $cwd);
        self::assertSame(0, $compile['code'], $compile['stdout'] . $compile['stderr']);
        self::assertFileExists($cwd . '/cache/manifest.php');

        $again = self::runBin($bin, ['compile', '--check', '--cache=' . $cwd . '/cache', 'components'], $cwd);
        self::assertSame(0, $again['code'], $again['stdout'] . $again['stderr']);

        self::assertSame(1, self::runBin($bin, ['compile', '--chek'], $cwd)['code']);
    }

    public function testBinIsExecutable(): void
    {
        self::assertTrue(is_executable(self::repo('/bin/rosanna')));
    }
}
