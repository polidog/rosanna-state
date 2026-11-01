<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Html\getFunctionComponentName;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Protocol\CsrfGuard;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RendererFactory;
use Polidog\Rosanna\Runtime\RenderScope;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Runtime\Snapshot;
use Polidog\Rosanna\Runtime\StateDiff;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

/** Renderer が担う SN-10〜12、AC-13、AC-15、AC-18、AC-20、VR-1、VR-7 */
final class SnapshotRenderTest extends TestCase
{
    use RendersInScope;
    use SessionTestHelper;

    protected function setUp(): void
    {
        RequestLifecycle::reset();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    /** @return list<array{id: int, done: bool}> */
    private static function rows(int $count = 100): array
    {
        return array_map(static fn(int $i) => ['id' => $i, 'done' => false], range(0, $count - 1));
    }

    private static function list(StorageType $type): Element
    {
        return fc(static function () {
            [$rows, $set] = useState(self::rows());

            return H::div(children: array_map(static function (array $row) use ($rows, $set) {
                return H::button(onClick: static function () use ($rows, $row, $set) {
                    $rows[$row['id']]['done'] = true;

                    return $set($rows);
                }, children: (string) $row['id']);
            }, $rows));
        }, 'list', $type)();
    }

    public function testSN10FormOfASnapshotInstanceWithoutASecretIsLogicException(): void
    {
        $scope = self::scope(null);
        ComponentState::fromSnapshot(new Snapshot('C', '0'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('secret');
        $scope->renderers->create('C#0')->renderElement(H::button(onClick: Action::setState(0, 1, 'C#0'), children: 'x'));
    }

    public function testSN10RendererRenderWithoutASecretIsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        self::scope(null)->renderers->create('C#0', StorageType::Snapshot)->render(static fn() => H::div());
    }

    public function testSN11WrapperAndFormBothCarryTheSnapshotOnce(): void
    {
        $html = self::html(static fn() => self::list(StorageType::Snapshot));
        $document = self::dom($html);
        $wrapper = $document->querySelector('[data-rosanna]');
        $forms = $document->querySelectorAll('form');

        self::assertSame(1, $forms->length);
        self::assertSame(
            $wrapper?->getAttribute('data-rosanna-snapshot'),
            $forms->item(0)?->querySelector('input[name="_rosanna_snapshot"]')?->getAttribute('value'),
        );
        $snapshot = new SnapshotSerializer(self::SECRET)->deserialize((string) $wrapper?->getAttribute('data-rosanna-snapshot'));
        self::assertSame(self::rows(), $snapshot->state[0]);
    }

    public function testSN11ComponentWithoutActionsStillHasTheAttribute(): void
    {
        $html = self::html(static fn() => fc(static fn() => H::div(), 'k', StorageType::Snapshot)());
        self::assertStringContainsString('data-rosanna-snapshot=', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testSN12PartialEndsWithTheSnapshotUpdate(): void
    {
        $scope = self::scope();
        $html = RenderContext::run($scope, static function () use ($scope) {
            $wrapper = self::list(StorageType::Snapshot);

            return $scope->renderers->create($wrapper->props['data-rosanna'], StorageType::Snapshot)->renderPartial(static fn() => $wrapper->children);
        });

        self::assertStringNotContainsString('data-rosanna=', $html, 'only the inside of the wrapper');
        self::assertMatchesRegularExpression('#</form><input type="hidden" name="_rosanna_snapshot" value="[^"]+" data-rosanna-snapshot-update />$#', $html);
    }

    public function testSN12PartialOfOtherStoragesHasNoUpdate(): void
    {
        $scope = self::scope();
        $html = $scope->renderers->create('C#0', StorageType::Memory)->renderPartial(static fn() => H::p());
        self::assertSame('<p></p>', $html);
    }

    public function testRendererRenderMatchesFcForSnapshot(): void
    {
        $scope = self::scope();
        $html = $scope->renderers->create('C#0', StorageType::Snapshot)->render(static fn() => H::p());
        $wrapper = self::dom($html)->querySelector('[data-rosanna="C#0"]');
        self::assertSame('C#0', new SnapshotSerializer(self::SECRET)->deserialize((string) $wrapper?->getAttribute('data-rosanna-snapshot'))->getInstanceId());
        self::assertNotSame('', (string) $wrapper?->getAttribute('data-rosanna-restore-sig'), 'VR-7');
    }

    public function testVR7RestoreSignatureVerifies(): void
    {
        $wrapper = self::dom(self::html(static fn() => self::list(StorageType::Snapshot)))->querySelector('[data-rosanna]');
        self::assertTrue(new ActionSigner(self::SECRET)->verify(
            (string) $wrapper?->getAttribute('data-rosanna'),
            'restore',
            (string) $wrapper?->getAttribute('data-rosanna-restore-sig'),
            '/page',
        ));
    }

    public function testVR1ActionJsonCarriesTheRenderTimeVersion(): void
    {
        $inner = static function () {
            [$count, $set] = useState(0);

            return H::button(onClick: static fn() => $set($count + 1), children: 'x');
        };
        ComponentState::fromSnapshot(new Snapshot(getFunctionComponentName($inner), 'k', [0 => 1], [], 7));
        ComponentState::setCurrent(null);

        $html = self::html(static fn() => fc($inner, 'k', StorageType::Snapshot)());
        $document = self::dom($html);

        self::assertSame(7, self::envelope((string) $document->querySelector('button')?->getAttribute('value'))['action']['version']);
        $snapshot = new SnapshotSerializer(self::SECRET)->deserialize((string) $document->querySelector('[data-rosanna]')?->getAttribute('data-rosanna-snapshot'));
        self::assertSame(7, $snapshot->version);
    }

    public function testVR1VersionIsZeroWithoutASavedOne(): void
    {
        $button = self::dom(self::html(static fn() => H::button(onClick: Action::setState(0, 1, 'Fresh#0'), children: 'x')))->querySelector('button');
        self::assertSame(0, self::envelope((string) $button?->getAttribute('value'))['action']['version']);
    }

    public function testVR1VersionFollowsTheState(): void
    {
        $state = ComponentState::getInstance('Memory#k', StorageType::Memory);
        $state->incrementVersion();
        $state->incrementVersion();
        ComponentState::setCurrent(null);

        $button = self::dom(self::html(static fn() => H::button(onClick: Action::setState(0, 1, 'Memory#k'), children: 'x')))->querySelector('button');
        self::assertSame(2, self::envelope((string) $button?->getAttribute('value'))['action']['version']);
    }

    public function testVR1VersionIsSigned(): void
    {
        $button = self::dom(self::html(static fn() => self::list(StorageType::Memory)))->querySelector('button');
        $envelope = self::envelope((string) $button?->getAttribute('value'));
        $forged = str_replace('"version":0', '"version":9', $envelope['json']);

        self::assertNotSame($envelope['json'], $forged);
        $signer = new ActionSigner(self::SECRET);
        self::assertIsString($envelope['sig']);
        self::assertTrue($signer->verify((string) $envelope['action']['componentId'], $envelope['json'], $envelope['sig'], '/page'));
        self::assertFalse($signer->verify((string) $envelope['action']['componentId'], $forged, $envelope['sig'], '/page'));
    }

    public function testAC13AndAC15ButtonsCarryADiffAgainstTheRenderedValue(): void
    {
        $buttons = self::dom(self::html(static fn() => self::list(StorageType::Snapshot)))->querySelectorAll('button');
        self::assertCount(100, $buttons);

        foreach ([0, 42, 99] as $i) {
            $action = self::envelope((string) $buttons->item($i)?->getAttribute('value'))['action'];
            self::assertSame(StateDiff::hash(self::rows()), $action['payload']['base']);
            self::assertArrayNotHasKey('value', $action['payload']);

            $expected = self::rows();
            $expected[$i]['done'] = true;
            self::assertSame($expected, StateDiff::apply(self::rows(), $action['payload']['patch']));
        }
    }

    public function testAC18UnsavedMemoryStateCarriesTheBaseOnce(): void
    {
        $html = self::html(static fn() => self::list(StorageType::Memory));
        $document = self::dom($html);
        $fields = $document->querySelectorAll('input[name="_rosanna_base[0]"]');

        self::assertSame(1, $fields->length);
        self::assertSame(self::rows(), json_decode((string) $fields->item(0)?->getAttribute('value'), true));
        self::assertSame(1, $document->querySelectorAll('form')->length);
    }

    /** Memory の保存値はリクエストを越えないので、保存値があっても次の POST の基準に要る。Session は testAC18BaseFieldFlow */
    public function testAC18MemoryCarriesTheBaseEvenWhenTheValueIsSaved(): void
    {
        ComponentState::getInstance(self::listId(), StorageType::Memory)->setState(0, self::rows());
        self::assertStringContainsString('_rosanna_base', self::html(static fn() => self::list(StorageType::Memory)));
    }

    public function testAC18SnapshotNeverCarriesTheBase(): void
    {
        self::assertStringNotContainsString('_rosanna_base', self::html(static fn() => self::list(StorageType::Snapshot)));
    }

    public function testAC18PlainSetStateDoesNotCarryABase(): void
    {
        $html = self::html(static fn() => fc(static function () {
            [$count, $set] = useState(0);

            return H::button(onClick: static fn() => $set($count + 1), children: 'x');
        }, 'k', StorageType::Memory)());
        self::assertStringNotContainsString('_rosanna_base', $html);
    }

    public function testAC18IndividualFormCarriesItsOwnBase(): void
    {
        $html = self::html(static fn() => fc(static function () {
            [$rows, $set] = useState(self::rows());
            $next = $rows;
            $next[0]['done'] = true;

            return H::form(onSubmit: static fn() => $set($next));
        }, 'k', StorageType::Memory)());

        $form = self::dom($html)->querySelector('form');
        self::assertNotNull($form?->querySelector('input[name="_rosanna_base[0]"]'));
        self::assertNotNull($form?->querySelector('input[name="_rosanna_action"]'));
    }

    #[RunInSeparateProcess]
    public function testAC18CookielessGetCarriesTheBaseAndStartsNoSession(): void
    {
        self::isolateSessions();
        $html = self::html(static fn() => self::list(StorageType::Session));

        self::assertStringContainsString('_rosanna_base[0]', $html);
        self::assertStringNotContainsString('_rosanna_csrf', $html);
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame([], headers_list());
    }

    #[RunInSeparateProcess]
    public function testAC18SavedSessionStateDropsTheBase(): void
    {
        self::isolateSessions();
        session_start();
        ComponentState::getInstance(self::listId(), StorageType::Session)->setState(0, self::rows());

        $html = self::html(static fn() => self::list(StorageType::Session));

        self::assertStringNotContainsString('_rosanna_base', $html);
        self::assertStringContainsString('name="_rosanna_csrf"', $html);
    }

    public function testAC20BothFormsUseTheEnvelope(): void
    {
        $document = self::dom(self::html(static fn() => H::Fragment([
            H::button(onClick: Action::setState(0, 1, 'C#0'), children: 'a'),
            H::button(name: 'n', onClick: Action::setState(0, 2, 'C#0'), children: 'b'),
        ])));

        self::assertIsString(self::envelope((string) $document->querySelector('button:not([form=""])[value]')?->getAttribute('value'))['sig']);
        self::assertIsString(self::envelope((string) $document->querySelector('input[name="_rosanna_action"]')?->getAttribute('value'))['sig']);
        self::assertNull($document->querySelector('input[name="_rosanna_action_sig"]'));
    }

    /** セッションを開始できず秘密鍵もない。SG-3 の null が sig に出て、restore の署名の属性は付かない */
    #[RunInSeparateProcess]
    public function testAC20AndVR7NoKeyMeansANullSignatureAndNoRestoreAttribute(): void
    {
        self::isolateSessions();
        session_save_path('/nonexistent/rosanna-no-session');
        $signer = new ActionSigner()->forRequest(new Request('GET', '/page', '/page', ''));
        $serializer = new SnapshotSerializer(self::SECRET);
        $scope = new RenderScope(new RendererFactory($serializer, $signer, new CsrfGuard()), new PsxRenderer(), $serializer, $signer, 'page:/page');

        $document = self::dom(self::html(static fn() => fc(static function () {
            [, $set] = useState(0);

            return H::button(onClick: static fn() => $set(1), children: 'x');
        }, 'k', StorageType::Snapshot)(), $scope));

        self::assertNull(self::envelope((string) $document->querySelector('button')?->getAttribute('value'))['sig']);
        self::assertFalse($document->querySelector('[data-rosanna]')?->hasAttribute('data-rosanna-restore-sig'));
    }

    /** 秘密鍵がないとボタンの署名がセッションを始める。そのトークンがフォームに入る (SG-3、RT-4.6) */
    #[RunInSeparateProcess]
    public function testCsrfFieldFollowsASessionStartedBySigning(): void
    {
        self::isolateSessions();
        $html = self::html(static fn() => H::button(onClick: Action::setState(0, 1, 'C#0'), children: 'x'), self::scope(null));

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame($_SESSION['_rosanna_csrf'], self::dom($html)->querySelector('input[name="_rosanna_csrf"]')?->getAttribute('value'));
        self::assertIsString(self::envelope((string) self::dom($html)->querySelector('button')?->getAttribute('value'))['sig']);
    }

    /** self::list() のインスタンス ID (FC の名前は定義の行で決まる) */
    private static function listId(): string
    {
        $wrapper = self::list(StorageType::Memory);
        $id = $wrapper->props['data-rosanna'];
        self::assertIsString($id);
        RequestLifecycle::reset();

        return $id;
    }
}
