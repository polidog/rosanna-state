<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Html\createElement;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useFormAction;
use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-4.1〜4.3 属性名は大文字小文字を区別しない。RT-4.5 wrapper の form の中に共有フォームを入れない */
final class ActionBindingCaseTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    private static function action(): Action
    {
        return Action::setState(0, 1, 'C#0', StorageType::Memory);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badSubmitTargets(): iterable
    {
        yield 'ACTION' => [['ACTION' => 'https://evil.example/']];
        yield 'Action' => [['Action' => '/x']];
        yield 'METHOD' => [['METHOD' => 'get']];
        yield 'Method' => [['Method' => 'dialog']];
    }

    /** @param array<string, mixed> $props */
    #[DataProvider('badSubmitTargets')]
    public function testRT42FormAttributesAreCheckedWhateverTheirCase(array $props): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('onSubmit posts the action to the current page');
        self::html(static fn() => createElement('form', ['onSubmit' => self::action()] + $props));
    }

    public function testRT42UppercasePostMethodDoesNotDuplicateTheAttribute(): void
    {
        $form = self::dom(self::html(static fn() => createElement('form', ['onSubmit' => self::action(), 'METHOD' => 'POST'])))->querySelector('form');

        self::assertNotNull($form);
        self::assertSame('post', strtolower((string) $form->getAttribute('method')));
        self::assertSame(1, preg_match_all('/\bmethod=/i', self::html(static fn() => createElement('form', ['onSubmit' => self::action(), 'METHOD' => 'POST']))));
    }

    /** @return iterable<string, array{string}> */
    public static function buttonIdentity(): iterable
    {
        yield 'VALUE' => ['VALUE'];
        yield 'NAME' => ['NAME'];
        yield 'Value' => ['Value'];
    }

    /** RT-4.1 利用者の name と value を上書きしない */
    #[DataProvider('buttonIdentity')]
    public function testRT41UserButtonValueIsKeptWhateverItsCase(string $attribute): void
    {
        $html = self::html(static fn() => createElement('button', ['onClick' => self::action(), $attribute => 'mine']));
        $button = self::dom($html)->querySelector('button');

        self::assertNotNull($button);
        self::assertSame('mine', $button->getAttribute(strtolower($attribute)));
        self::assertNotSame('_rosanna_action', $button->getAttribute('name'));
        self::assertSame(1, preg_match_all('/\b' . strtolower($attribute) . '=/i', (string) strstr($html, '</button>', true)));
        self::assertNotNull(self::dom($html)->getElementById((string) $button->getAttribute('form')));
    }

    /** RT-4.1 パーサーは最初の属性を読むので、大文字の type が残るとボタンが送信しない */
    public function testRT41ButtonTypeIsSubmitWhateverTheUserCase(): void
    {
        $html = self::html(static fn() => createElement('button', ['onClick' => self::action(), 'TYPE' => 'button']));
        $button = self::dom($html)->querySelector('button');

        self::assertNotNull($button);
        self::assertSame('submit', $button->getAttribute('type'));
    }

    public function testRT41ButtonFormAttributeIsOursWhateverTheUserCase(): void
    {
        $html = self::html(static fn() => createElement('button', ['onClick' => self::action(), 'FORM' => 'victim']));
        $button = self::dom($html)->querySelector('button');

        self::assertNotNull($button);
        self::assertStringStartsWith('rosanna-f-', (string) $button->getAttribute('form'));
    }

    /** @return iterable<string, array{string}> */
    public static function submitInputs(): iterable
    {
        yield 'submit' => ['submit'];
        yield 'image' => ['IMAGE'];
    }

    #[DataProvider('submitInputs')]
    public function testRT41InputTypeIsReadWhateverItsCase(string $type): void
    {
        $html = self::html(static fn() => createElement('input', ['onClick' => self::action(), 'TYPE' => $type]));
        $input = self::dom($html)->querySelector('input');

        self::assertNotNull($input);
        self::assertFalse($input->hasAttribute('data-rosanna-on-click'));
        self::assertNotNull(self::dom($html)->getElementById((string) $input->getAttribute('form')));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badFieldTriggers(): iterable
    {
        yield 'MULTIPLE' => [['name' => 'q', 'MULTIPLE' => true]];
        yield 'TYPE file' => [['name' => 'q', 'TYPE' => 'FILE']];
        yield 'NAME of another field' => [['NAME' => 'other']];
    }

    /** @param array<string, mixed> $props */
    #[DataProvider('badFieldTriggers')]
    public function testRT43FieldTriggerChecksIgnoreTheCase(array $props): void
    {
        $component = fc(static function () use ($props): Element {
            [, $set] = useState('');

            return createElement('input', ['onChange' => useFormAction(['q' => $set])] + $props);
        }, storageType: StorageType::Memory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('useFormAction() cannot be used here');
        self::html(static fn() => H::div(children: [$component()]));
    }

    public function testRT43UppercaseNameOfTheOwnFieldIsAccepted(): void
    {
        $component = fc(static function (): Element {
            [, $set] = useState('');

            return createElement('input', ['onChange' => useFormAction(['q' => $set]), 'NAME' => 'q']);
        }, storageType: StorageType::Memory);

        self::assertStringContainsString('NAME="q"', self::html(static fn() => H::div(children: [$component()])));
    }

    // --- RT-4.5 ---

    private static function assertNoNestedForm(string $html): void
    {
        self::assertSame(0, self::dom($html)->querySelectorAll('form form')->length, $html);
        // DOM のパーサーは入れ子の開始タグを捨てるので、マークアップでも確かめる
        $depth = 0;
        preg_match_all('~<(/?)form\b~i', $html, $tags);
        foreach ($tags[1] as $slash) {
            $depth += $slash === '' ? 1 : -1;
            self::assertLessThanOrEqual(1, $depth, $html);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function formTags(): iterable
    {
        yield 'lower' => ['form'];
        yield 'upper' => ['FORM'];
    }

    #[DataProvider('formTags')]
    public function testRT45FormThatIsItselfAWrapperGetsNoSharedFormInside(string $tag): void
    {
        $html = self::html(static fn() => new Element($tag, ['data-rosanna' => 'W#0'], [H::button(onClick: self::action())]));

        self::assertNoNestedForm($html);
        $button = self::dom($html)->querySelector('button');
        self::assertNotNull($button);
        self::assertNotNull(self::dom($html)->getElementById((string) $button->getAttribute('form')));
    }

    #[DataProvider('formTags')]
    public function testRT45ButtonInAFormWhateverTheCaseOfTheTag(string $tag): void
    {
        $html = self::html(static fn() => new Element('div', [], [new Element($tag, [], [H::p(children: [H::button(onClick: self::action())])])]));

        self::assertNoNestedForm($html);
        self::assertMatchesRegularExpression('~</form><form method="post" data-rosanna-form~i', $html);
    }

    public function testRT45WrapperFormInsideAnApplicationFormStaysFlat(): void
    {
        $html = self::html(static fn() => new Element('div', [], [new Element('form', [], [new Element('form', ['data-rosanna' => 'W#0'], [H::button(onClick: self::action())])])]));

        self::assertNoNestedForm(str_replace('<form data-rosanna="W#0">', '<section>', str_replace('</form></form>', '</section></form>', $html)));
    }
}
