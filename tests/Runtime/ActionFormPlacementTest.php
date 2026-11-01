<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Protocol\CsrfGuard;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

/**
 * フォームの配置 (RT-4.4〜4.9)。Dom\HTMLDocument は form や table の入れ子をブラウザと同じ規則で扱う。
 */
final class ActionFormPlacementTest extends TestCase
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

    private static function button(int $value = 1, string $target = 'C#0'): Element
    {
        return H::button(onClick: Action::setState(0, $value, $target), children: 'b' . $value);
    }

    private static function formOfButton(\Dom\HTMLDocument $document, ?\Dom\Element $button): \Dom\Element
    {
        self::assertNotNull($button);
        $form = $document->getElementById((string) $button->getAttribute('form'));
        self::assertNotNull($form, 'the button must point at an existing form');

        return $form;
    }

    public function testRT44FormShapeAndIdFormat(): void
    {
        $html = self::html(static fn() => self::button());
        self::assertMatchesRegularExpression('/<form method="post" data-rosanna-form id="rosanna-f-[0-9a-f]{16}" hidden>/', $html);
    }

    public function testRT44RendersOfTheSameComponentGetDifferentIds(): void
    {
        $first = self::dom(self::html(static fn() => self::button()))->querySelector('button');
        $second = self::dom(self::html(static fn() => self::button()))->querySelector('button');
        self::assertNotSame($first?->getAttribute('form'), $second?->getAttribute('form'));
    }

    public function testRT45ButtonInsideApplicationFormDoesNotNest(): void
    {
        $html = self::html(static fn() => H::div(children: [H::form(action: '/s', children: [H::input(name: 'q'), self::button()]), H::p(children: 'after')]));
        $document = self::dom($html);

        self::assertNull($document->querySelector('form form'));
        self::assertSame(2, $document->querySelectorAll('form')->length);
        self::assertSame('q', $document->querySelector('form[action="/s"] input')?->getAttribute('name'));
        self::assertNotNull($document->querySelector('form[action="/s"] button'), 'the outer form must not be closed early');
        self::formOfButton($document, $document->querySelector('button'));
        self::assertMatchesRegularExpression('#</button></form><form method="post"#', $html, 'goes right after the outermost form');
    }

    public function testRT45NestedApplicationFormsFlushAfterTheOutermost(): void
    {
        $html = self::html(static fn() => H::form(children: [H::div(children: [H::form(children: [self::button()]), 'tail'])]));
        self::assertMatchesRegularExpression('#tail</div></form><form method="post"#', $html);
    }

    public function testRT45ButtonInsideParagraphDoesNotSplitIt(): void
    {
        $document = self::dom(self::html(static fn() => H::p(id: 'p', children: ['a', self::button(), 'z'])));
        self::assertSame(1, $document->querySelectorAll('p')->length);
        self::assertNotNull($document->querySelector('p > button'));
        self::assertSame('az', str_replace('b1', '', (string) $document->querySelector('p')?->textContent));
    }

    public function testRT45TableStaysIntact(): void
    {
        $document = self::dom(self::html(static fn() => H::table(children: [H::tbody(children: [
            H::tr(children: [H::td(children: [self::button()])]),
            H::tr(children: [H::td(children: [self::button(2)])]),
        ])])));

        self::assertNull($document->querySelector('table form'));
        self::assertSame(2, $document->querySelectorAll('table td button')->length);
        self::formOfButton($document, $document->querySelector('button'));
    }

    public function testRT45WrapperOwnsItsForms(): void
    {
        $html = self::html(static fn() => fc(static function () {
            [, $set] = useState(0);

            return H::button(onClick: static fn() => $set(1), children: 'x');
        }, 'k')());
        $document = self::dom($html);

        self::assertNotNull($document->querySelector('[data-rosanna] > form'));
        self::assertStringEndsWith('</form></div>', $html);
    }

    public function testRT45ParentFormDoesNotMoveIntoChildWrapper(): void
    {
        $child = fc(static fn() => H::span(children: 'child'), 'child');
        $html = self::html(static fn() => fc(static function () use ($child) {
            [, $set] = useState(0);

            return H::div(children: [
                H::button(onClick: static fn() => $set(1), children: 'before'),
                $child(),
                H::button(onClick: static fn() => $set(2), children: 'after'),
            ]);
        }, 'parent')());
        $document = self::dom($html);

        $childWrapper = $document->querySelector('[data-rosanna$="#child"]');
        self::assertNotNull($childWrapper);
        self::assertNull($childWrapper->querySelector('form'), 'a partial update of the child must not take the parent form away');
        self::assertSame(1, $document->querySelectorAll('form')->length);
        self::assertStringEndsWith('</form></div>', $html);
    }

    public function testRT45ComponentInsideApplicationFormPutsItsFormAfterTheOuterForm(): void
    {
        $html = self::html(static fn() => H::form(action: '/s', children: [fc(static function () {
            [, $set] = useState(0);

            return H::button(onClick: static fn() => $set(1), children: 'x');
        }, 'k')()]));
        $document = self::dom($html);

        self::assertSame(2, $document->querySelectorAll('form')->length);
        self::assertNull($document->querySelector('form form'));
        self::assertNotNull($document->querySelector('form[action="/s"] [data-rosanna] > button'));
        self::assertNull($document->querySelector('[data-rosanna] form'));
    }

    public function testRT45RemainingFormsGoAtTheEndOfTheOutermostRender(): void
    {
        $html = self::html(static fn() => H::Fragment([self::button(), H::p(children: 'tail')]));
        self::assertMatchesRegularExpression('#<p>tail</p><form method="post"#', $html);
    }

    public function testRT45ExceptionLeavesNoFormsBehind(): void
    {
        $renderer = self::scope()->renderers->create('_root_');
        try {
            $renderer->renderElement(new Element('div', [], [self::button(), new \stdClass()]));
            self::fail();
        } catch (\RuntimeException) {
        }

        self::assertSame('<p></p>', $renderer->renderElement(H::p()));
    }

    #[RunInSeparateProcess]
    public function testRT46PageWithoutActionsDoesNotStartASession(): void
    {
        self::isolateSessions();
        self::scope(null)->renderers->create('_root_')->renderElement(H::div(children: 'x'));
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testRT46TokenIsReadWhenTheFormIsWritten(): void
    {
        self::isolateSessions();
        // 秘密鍵があるのでボタンの署名はセッションを始めない。描画の途中で始まったセッションのトークンがフォームに入る
        $starter = H::button(onClick: Action::setState(0, 1, 'C#0'), children: 'x');
        $html = self::html(static function () use ($starter) {
            return H::Fragment([$starter, new class implements \Polidog\Rosanna\Runtime\Renderable {
                public function render(): Element
                {
                    session_start();

                    return H::p();
                }
            }]);
        }, self::scope());

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        $field = self::dom($html)->querySelector('input[name="_rosanna_csrf"]');
        self::assertSame($_SESSION['_rosanna_csrf'], $field?->getAttribute('value'));
    }

    public function testRT46NoFieldWithoutASession(): void
    {
        self::assertStringNotContainsString('_rosanna_csrf', self::html(static fn() => self::button()));
    }

    public function testRT47ManyButtonsShareOneForm(): void
    {
        $document = self::dom(self::html(static fn() => H::div(children: array_map(static fn(int $i) => self::button($i), range(1, 50)))));

        self::assertSame(1, $document->querySelectorAll('form')->length);
        $ids = [];
        foreach ($document->querySelectorAll('button') as $button) {
            $ids[$button->getAttribute('form')] = true;
            self::assertSame('_rosanna_action', $button->getAttribute('name'));
        }
        self::assertCount(1, $ids);
    }

    public function testRT47OtherTargetsGetTheirOwnForm(): void
    {
        $document = self::dom(self::html(static fn() => H::div(children: [self::button(1, 'A#0'), self::button(2, 'B#0'), self::button(3, 'A#0')])));

        self::assertSame(2, $document->querySelectorAll('form')->length);
        $buttons = $document->querySelectorAll('button');
        self::assertSame($buttons->item(0)?->getAttribute('form'), $buttons->item(2)?->getAttribute('form'));
        self::assertNotSame($buttons->item(0)?->getAttribute('form'), $buttons->item(1)?->getAttribute('form'));
        self::assertSame('B#0', self::formOfButton($document, $buttons->item(1))->querySelector('input[name="_rosanna_component"]')?->getAttribute('value'));
    }

    public function testRT47SharedFormAlsoServesNonButtonElements(): void
    {
        $document = self::dom(self::html(static fn() => H::div(children: [self::button(), H::span(onClick: Action::setState(0, 2, 'C#0'), children: 's')])));

        self::assertSame(1, $document->querySelectorAll('form')->length);
        self::assertSame(
            $document->querySelector('button')?->getAttribute('form'),
            $document->querySelector('span')?->getAttribute('data-rosanna-on-click'),
        );
    }

    public function testRT47AnInnerWrapperIsItsOwnScope(): void
    {
        $inner = new Element('div', ['data-rosanna' => 'Inner#0'], [self::button(1, 'P#0')]);
        $document = self::dom(self::html(static fn() => new Element('div', ['data-rosanna' => 'P#0'], [self::button(2, 'P#0'), $inner, self::button(3, 'P#0')])));

        self::assertSame(2, $document->querySelectorAll('form')->length);
        $buttons = $document->querySelectorAll('button');
        self::assertSame($buttons->item(0)?->getAttribute('form'), $buttons->item(2)?->getAttribute('form'));
        self::assertNotNull($document->querySelector('[data-rosanna="Inner#0"] > form'), 'a parent setter in a child uses a form inside the child');
        self::assertSame((string) $buttons->item(1)?->getAttribute('form'), $document->querySelector('[data-rosanna="Inner#0"] > form')?->getAttribute('id'));
    }

    public function testRT47ScopeIsPerOutermostRender(): void
    {
        $renderer = self::scope()->renderers->create('_root_');
        $first = self::dom($renderer->renderElement(self::button()))->querySelector('button');
        $second = self::dom($renderer->renderElement(self::button()))->querySelector('button');
        self::assertNotSame($first?->getAttribute('form'), $second?->getAttribute('form'));
    }

    public function testRT48ApplicationFormPerRowDoesNotMultiplySharedForms(): void
    {
        $rows = array_map(static fn(int $i) => H::form(action: '/row', children: [H::input(name: "n{$i}"), self::button($i)]), range(1, 20));
        $html = self::html(static fn() => H::div(children: $rows));
        $document = self::dom($html);

        self::assertSame(21, $document->querySelectorAll('form')->length, 'the 20 application forms and 1 shared form');
        self::assertNull($document->querySelector('form form'));
        $ids = [];
        foreach ($document->querySelectorAll('button') as $button) {
            $ids[$button->getAttribute('form')] = true;
            self::formOfButton($document, $button);
        }
        self::assertCount(1, $ids, 'a written form keeps being reused');
    }

    public function testRT48MissingBaseAfterWritingCreatesANewFormThatKeepsEarlierBases(): void
    {
        $component = fc(static function () {
            [$a, $setA] = useState(self::longList(1));
            [$b, $setB] = useState(self::longList(2));

            $toggle = static function (array $list): array {
                $list[0] = 'changed';

                return $list;
            };

            return H::Fragment([
                H::form(children: [H::button(onClick: static fn() => $setA($toggle($a)), children: 'a')]),
                H::form(children: [H::button(onClick: static fn() => $setB($toggle($b)), children: 'b')]),
                H::form(children: [H::button(onClick: static fn() => $setA($toggle($a)), children: 'a2')]),
            ]);
        }, 'k', StorageType::Memory);

        $document = self::dom(self::html(static fn() => H::div(children: [$component()])));
        $buttons = $document->querySelectorAll('button');
        self::assertCount(3, $buttons);

        $first = self::formOfButton($document, $buttons->item(0));
        $second = self::formOfButton($document, $buttons->item(1));
        self::assertNotSame($first->getAttribute('id'), $second->getAttribute('id'), 'the base cannot be added to a written form');
        self::assertNotNull($first->querySelector('input[name="_rosanna_base[0]"]'));
        self::assertNull($first->querySelector('input[name="_rosanna_base[1]"]'));
        self::assertNotNull($second->querySelector('input[name="_rosanna_base[0]"]'));
        self::assertNotNull($second->querySelector('input[name="_rosanna_base[1]"]'));
        self::assertSame($second->getAttribute('id'), $buttons->item(2)?->getAttribute('form'), 'the newer form already has every base');
        self::assertSame(5, $document->querySelectorAll('form')->length);
    }

    public function testRT49SubmitInputFormsAreIndividual(): void
    {
        $document = self::dom(self::html(static fn() => H::div(children: [
            H::input(type: 'submit', value: 'A', onClick: Action::setState(0, 1, 'C#0')),
            H::input(type: 'submit', value: 'B', onClick: Action::setState(0, 2, 'C#0')),
        ])));

        self::assertSame(2, $document->querySelectorAll('form')->length);
    }

    public function testRT44PartialUpdateInsideApplicationFormPointsAtTheNewForm(): void
    {
        $scope = self::scope();
        $build = static fn() => fc(static function () {
            [$count, $set] = useState(0);

            return H::button(onClick: static fn() => $set($count + 1), children: (string) $count);
        }, 'k', StorageType::Snapshot);

        $page = self::html(static fn() => H::form(action: '/s', children: [$build()()]), $scope);
        $oldId = (string) self::dom($page)->querySelector('button')?->getAttribute('form');
        $instanceId = (string) self::dom($page)->querySelector('[data-rosanna]')?->getAttribute('data-rosanna');

        // 部分更新の応答。新しいボタンが、ラッパーの外に残った古いスナップショットのフォームを指さないこと
        $fragment = RenderContext::run($scope, static function () use ($build, $scope, $instanceId) {
            $wrapper = $build()();
            self::assertSame($instanceId, $wrapper->props['data-rosanna']);

            return $scope->renderers->create($instanceId, StorageType::Snapshot)->renderPartial(static fn() => $wrapper->children);
        });

        $newDocument = self::dom($fragment);
        $newId = (string) $newDocument->querySelector('button')?->getAttribute('form');
        self::assertNotSame($oldId, $newId);
        self::assertNotNull($newDocument->getElementById($newId));
    }

    public function testRT41AndHD11DeferQueryIsCarriedByEveryForm(): void
    {
        $scope = self::scope();
        $html = $scope->renderers->create('_root_', deferQuery: 'a=1&b=2')->renderElement(H::Fragment([
            self::button(),
            H::form(onSubmit: Action::setState(0, 2, 'C#0')),
            H::input(type: 'submit', onClick: Action::setState(0, 3, 'C#0')),
        ]));

        self::assertSame(3, self::dom($html)->querySelectorAll('input[name="_rosanna_defer_query"][value="a=1&b=2"]')->length);
    }

    public function testRT48RawTextAndForeignContentKeepTheirRulesNextToForms(): void
    {
        $html = self::html(static fn() => H::div(children: [
            self::button(),
            H::style(children: 'a > b {}'),
            new Element('svg', [], [new Element('style', [], ['<x>'])]),
        ]));

        self::assertStringContainsString('<style>a > b {}</style>', $html);
        self::assertStringContainsString('<svg><style>&lt;x&gt;</style></svg>', $html);
    }

    public function testRT41EnvelopeSignatureVerifiesAgainstThePage(): void
    {
        $button = self::dom(self::html(static fn() => self::button()))->querySelector('button');
        $envelope = self::envelope((string) $button?->getAttribute('value'));

        $signer = new ActionSigner(self::SECRET);
        self::assertIsString($envelope['sig']);
        self::assertTrue($signer->verify('C#0', $envelope['json'], $envelope['sig'], '/page'));
        self::assertFalse($signer->verify('C#0', $envelope['json'] . ' ', $envelope['sig'], '/page'), 'AC-20');
        self::assertFalse($signer->verify('C#1', $envelope['json'], $envelope['sig'], '/page'));
        self::assertFalse($signer->verify('C#0', $envelope['json'], $envelope['sig'], '/other'));
    }

    public function testCurrentStateIsNotChangedByRenderingAForm(): void
    {
        $state = ComponentState::getInstance('Owner#0', StorageType::Memory);
        self::html(static fn() => self::button(1, 'Other#0'));
        self::assertSame($state, ComponentState::current());
    }

    #[RunInSeparateProcess]
    public function testCsrfIsDisabledByTheGuard(): void
    {
        self::isolateSessions();
        session_start();
        self::assertStringNotContainsString('_rosanna_csrf', self::html(static fn() => self::button(), self::scope(csrf: new CsrfGuard(false))));
        self::assertStringContainsString('_rosanna_csrf', self::html(static fn() => self::button(), self::scope(csrf: new CsrfGuard())));
    }

    /** @return list<string> */
    private static function longList(int $seed): array
    {
        return array_map(static fn(int $i) => "seed-{$seed}-row-{$i}", range(1, 50));
    }
}
