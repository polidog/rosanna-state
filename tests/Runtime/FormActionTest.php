<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useFormAction;
use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-7.5〜7.7 と Renderer の結合 */
final class FormActionTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testFormOnSubmitSignsTheFieldMap(): void
    {
        $html = self::html(static fn() => fc(static function () {
            [$name, $setName] = useState('');
            [, $setEmail] = useState('');

            return H::form(onSubmit: useFormAction(['name' => $setName, 'email' => $setEmail]), children: [H::input(name: 'name', value: $name)]);
        }, 'k', StorageType::Memory)());

        $form = self::dom($html)->querySelector('form');
        self::assertTrue($form?->hasAttribute('data-rosanna-with-value'));
        $envelope = self::envelope((string) $form?->querySelector('input[name="_rosanna_action"]')?->getAttribute('value'));
        self::assertSame(['fields' => ['name' => 0, 'email' => 1]], $envelope['action']['payload']);
        self::assertIsString($envelope['sig']);
    }

    public function testOnChangeSendsItsOwnField(): void
    {
        $html = self::html(static fn() => fc(static function () {
            [, $set] = useState('');

            return H::input(name: 'q', onChange: useFormAction(['q' => $set]));
        }, 'k', StorageType::Memory)());

        $input = self::dom($html)->querySelector('input[name="q"]');
        self::assertTrue($input?->hasAttribute('data-rosanna-with-value'));
        self::assertSame(['fields' => ['q' => 0]], self::envelope((string) $input?->getAttribute('data-rosanna-action-change'))['action']['payload']);
    }

    public function testRT77ClickWithFieldsIsRefusedByTheRenderer(): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => fc(static function () {
            [, $set] = useState('');

            return H::button(onClick: useFormAction(['q' => $set]));
        }, 'k', StorageType::Memory)());
    }
}
