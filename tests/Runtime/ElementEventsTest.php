<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Html\createElement;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-2.5、RT-2.6、RT-4.1、RT-4.2、RT-4.3 */
final class ElementEventsTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedEvents(): iterable
    {
        yield 'focus' => ['onFocus'];
        yield 'blur' => ['onBlur'];
        yield 'load' => ['onLoad'];
        yield 'error' => ['onError'];
    }

    #[DataProvider('unsupportedEvents')]
    public function testRT25UnsupportedEventIsRefused(string $prop): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($prop);
        createElement('div', [$prop => Action::setState(0, 1)]);
    }

    #[DataProvider('unsupportedEvents')]
    public function testRT25UnsupportedEventWithNullIsIgnored(string $prop): void
    {
        self::assertSame([], createElement('div', [$prop => null])->props);
    }

    public function testRT25UnsupportedEventOnDynamicPathIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::customTag(onBlur: Action::setState(0, 1));
    }

    public function testRT26ActionBecomesWireProp(): void
    {
        $action = Action::setState(0, 1);
        self::assertSame($action, createElement('div', ['onClick' => $action])->props['wire:click']);
        self::assertSame($action, createElement('div', ['onChange' => $action])->props['wire:change']);
        self::assertSame($action, createElement('div', ['onInput' => $action])->props['wire:input']);
        self::assertSame($action, createElement('form', ['onSubmit' => $action])->props['wire:submit']);
    }

    public function testRT26ClosureRunsWhileRendering(): void
    {
        $action = Action::setState(0, 1);
        self::assertSame($action, H::div(onClick: static fn() => $action)->props['wire:click']);
    }

    public function testRT26ClosureReturningAnythingElseAddsNoProp(): void
    {
        self::assertSame([], H::div(onClick: static fn() => null)->props);
        self::assertSame([], H::div(onClick: static fn() => 'phpinfo')->props);
    }

    public function testRT26ClosureWithRequiredParameterGetsAClearError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('useFormAction()');
        H::div(onClick: static fn(string $value) => null);
    }

    public function testRT41ButtonUsesTheSharedFormWithoutJavaScript(): void
    {
        $document = self::dom(self::html(static fn() => H::div(children: [H::button(onClick: Action::setState(0, 1, 'C#0'), children: 'x')])));
        $button = $document->querySelector('button');
        self::assertNotNull($button);
        self::assertSame('submit', $button->getAttribute('type'));
        self::assertSame('_rosanna_action', $button->getAttribute('name'));
        $form = $document->getElementById((string) $button->getAttribute('form'));
        self::assertNotNull($form);
        self::assertSame('FORM', $form->tagName);
        self::assertSame('post', $form->getAttribute('method'));
        self::assertTrue($form->hasAttribute('data-rosanna-form'));
        self::assertTrue($form->hasAttribute('hidden'));
        self::assertNull($form->querySelector('input[name="_rosanna_action"]'), 'the action is on the button');
        self::assertSame('C#0', $form->querySelector('input[name="_rosanna_component"]')?->getAttribute('value'));
        self::assertSame(['index' => 0, 'value' => 1], self::envelope((string) $button->getAttribute('value'))['action']['payload']);
    }

    public function testRT41UserNameOrValueOnButtonIsKept(): void
    {
        $document = self::dom(self::html(static fn() => H::Fragment([
            H::button(name: 'mine', onClick: Action::setState(0, 1, 'C#0'), children: 'a'),
            H::button(value: 'v', onClick: Action::setState(0, 2, 'C#0'), children: 'b'),
        ])));

        $buttons = $document->querySelectorAll('button');
        self::assertSame('mine', $buttons->item(0)?->getAttribute('name'));
        self::assertFalse($buttons->item(0)?->hasAttribute('value'));
        self::assertSame('v', $buttons->item(1)?->getAttribute('value'));
        self::assertFalse($buttons->item(1)?->hasAttribute('name'));
        self::assertSame(2, $document->querySelectorAll('form')->length, 'each gets an individual form');

        foreach ($buttons as $button) {
            $form = $document->getElementById((string) $button->getAttribute('form'));
            self::assertNotNull($form);
            $field = $form->querySelector('input[name="_rosanna_action"]');
            self::assertNotNull($field, 'the individual form carries the action');
            self::envelope((string) $field->getAttribute('value'));
        }
    }

    public function testRT41SubmitInputUsesAnIndividualForm(): void
    {
        foreach (['submit', 'image', 'SUBMIT'] as $type) {
            $html = self::html(static fn() => H::input(type: $type, value: 'Send', onClick: Action::setState(0, 1, 'C#0')));
            $document = self::dom($html);
            $input = $document->querySelector('input[form]');
            self::assertNotNull($input, $type);
            self::assertSame('Send', $input->getAttribute('value'));
            self::assertFalse($input->hasAttribute('data-rosanna-on-click'));
            self::assertNotNull($document->getElementById((string) $input->getAttribute('form'))?->querySelector('input[name="_rosanna_action"]'));
        }
    }

    /** @return iterable<string, array{\Closure, string}> */
    public static function jsDriven(): iterable
    {
        $action = Action::setState(0, 1, 'C#0');
        yield 'tr click' => [static fn() => H::table(children: [H::tbody(children: [H::tr(onClick: $action)])]), 'click'];
        yield 'div click' => [static fn() => H::div(onClick: $action), 'click'];
        yield 'input change' => [static fn() => H::input(onChange: $action), 'change'];
        yield 'text input click' => [static fn() => H::input(type: 'text', onClick: $action), 'click'];
        yield 'select change' => [static fn() => H::select(onChange: $action), 'change'];
        yield 'textarea input' => [static fn() => H::textarea(onInput: $action), 'input'];
    }

    #[DataProvider('jsDriven')]
    public function testRT41OtherElementsNeedTheClientScript(\Closure $build, string $event): void
    {
        $html = self::html($build);
        $document = self::dom($html);
        $element = $document->querySelector("[data-rosanna-on-{$event}]");
        self::assertNotNull($element, $html);
        self::assertNotNull($document->getElementById((string) $element->getAttribute("data-rosanna-on-{$event}")));
        self::assertSame('C#0', self::envelope((string) $element->getAttribute("data-rosanna-action-{$event}"))['action']['componentId']);
    }

    public function testRT41BothInputEventsCanBeBoundOnOneElement(): void
    {
        $html = self::html(static fn() => H::input(onChange: Action::setState(0, 1, 'C#0'), onInput: Action::setState(1, 2, 'C#0')));
        $input = self::dom($html)->querySelector('input');
        self::assertSame($input?->getAttribute('data-rosanna-on-change'), $input?->getAttribute('data-rosanna-on-input'));
        self::assertSame(1, self::envelope((string) $input?->getAttribute('data-rosanna-action-input'))['action']['payload']['index']);
    }

    public function testRT41FormOnSubmitIsItselfAnActionForm(): void
    {
        $html = self::html(static fn() => H::form(onSubmit: Action::setState(0, 1, 'C#0'), children: [H::input(name: 'q')]));
        self::assertStringStartsWith('<form method="post" data-rosanna-form><input type="hidden" name="_rosanna_component" value="C#0" />', $html);
        $form = self::dom($html)->querySelector('form');
        self::assertSame(1, self::dom($html)->querySelectorAll('form')->length);
        self::envelope((string) $form?->querySelector('input[name="_rosanna_action"]')?->getAttribute('value'));
    }

    public function testRT41ButtonInsideOnSubmitFormGetsItsOwnFormOutside(): void
    {
        $document = self::dom(self::html(static fn() => H::form(onSubmit: Action::setState(0, 1, 'C#0'), children: [H::button(onClick: Action::setState(1, 2, 'C#0'), children: 'x')])));
        self::assertSame(2, $document->querySelectorAll('form')->length);
        self::assertNull($document->querySelector('form form'));
    }

    public function testRT41NonActionWirePropIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be an Action');
        self::html(static fn() => new \Polidog\Rosanna\Runtime\Element('button', ['wire:click' => 'phpinfo']));
    }

    public function testRT41UnknownWireEventIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported event binding');
        self::html(static fn() => new \Polidog\Rosanna\Runtime\Element('div', ['wire:focus' => Action::setState(0, 1)]));
    }

    public function testRT42SubmitOnNonFormIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => createElement('div', ['onSubmit' => Action::setState(0, 1)]));
    }

    public function testRT42SubmitWithActionUrlIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => H::form(action: '/x', onSubmit: Action::setState(0, 1)));
    }

    public function testRT42SubmitWithGetIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => H::form(method: 'get', onSubmit: Action::setState(0, 1)));
    }

    public function testRT42SubmitWithExplicitPostIsAccepted(): void
    {
        self::assertStringContainsString('method="post"', self::html(static fn() => H::form(method: 'POST', onSubmit: Action::setState(0, 1))));
    }

    /** @return iterable<string, array{\Closure}> */
    public static function badFieldTriggers(): iterable
    {
        $action = Action::setFields(['q' => 0], 'C#0');
        yield 'click' => [static fn() => H::button(onClick: $action)];
        yield 'not a control' => [static fn() => H::div(onChange: $action)];
        yield 'multiple' => [static fn() => H::select(name: 'q', multiple: true, onChange: $action)];
        yield 'file' => [static fn() => H::input(type: 'file', name: 'q', onChange: $action)];
        yield 'other name' => [static fn() => H::input(name: 'r', onChange: $action)];
        yield 'no name' => [static fn() => H::input(onChange: $action)];
        yield 'two fields' => [static fn() => H::input(name: 'q', onChange: Action::setFields(['q' => 0, 'r' => 1], 'C#0'))];
    }

    #[DataProvider('badFieldTriggers')]
    public function testRT43FieldsActionOnAnElementThatCannotSendThemIsRefused(\Closure $build): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('useFormAction()');
        self::html($build);
    }

    public function testRT43FieldsActionOnItsOwnControlSendsTheValue(): void
    {
        $input = self::dom(self::html(static fn() => H::input(name: 'q', onChange: Action::setFields(['q' => 0], 'C#0'))))->querySelector('input');
        self::assertTrue($input?->hasAttribute('data-rosanna-with-value'));
    }

    public function testRT43PlainActionDoesNotSendTheValue(): void
    {
        $input = self::dom(self::html(static fn() => H::input(name: 'q', onChange: Action::setState(0, 1, 'C#0'))))->querySelector('input');
        self::assertFalse($input?->hasAttribute('data-rosanna-with-value'));
    }

    public function testRT43SubmitWithFieldsIsMarkedToo(): void
    {
        $form = self::dom(self::html(static fn() => H::form(onSubmit: Action::setFields(['q' => 0, 'r' => 1], 'C#0'))))->querySelector('form');
        self::assertTrue($form?->hasAttribute('data-rosanna-with-value'));
    }
}
