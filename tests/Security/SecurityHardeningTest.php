<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Security;

use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Html\createElement;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\State\Action;
use Polidog\Rosanna\State\Tests\Runtime\Support\RendersInScope;

/** 出力の安全性と、イベントの prop の実行の防止 (RT-2.6、RT-3) */
final class SecurityHardeningTest extends TestCase
{
    use RendersInScope;

    public static int $invoked = 0;

    protected function setUp(): void
    {
        self::$invoked = 0;
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testRT34InvalidTagOnActionElementThrowsBeforeSigning(): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => new Element('b"utton', ['wire:click' => Action::setState(0, 1)]));
    }

    public function testRT26EventPropRejectsStringCallableWithoutExecutingIt(): void
    {
        foreach (['phpinfo', 'system', __CLASS__ . '::touch'] as $callable) {
            try {
                createElement('div', ['onClick' => $callable]);
                self::fail($callable);
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(0, self::$invoked);
    }

    public function testRT26EventPropRejectsArrayCallable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            createElement('div', ['onChange' => [self::class, 'touch']]);
        } finally {
            self::assertSame(0, self::$invoked);
        }
    }

    public function testRT26EventPropRejectsInvokableObject(): void
    {
        $invokable = new class {
            public function __invoke(): void
            {
                SecurityHardeningTest::touch();
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        try {
            createElement('button', ['onClick' => $invokable]);
        } finally {
            self::assertSame(0, self::$invoked);
        }
    }

    public function testRT26EventPropRejectsScalars(): void
    {
        foreach ([1, 1.5, true, false, ''] as $value) {
            try {
                createElement('form', ['onSubmit' => $value]);
                self::fail(var_export($value, true));
            } catch (\InvalidArgumentException) {
                self::assertSame(0, self::$invoked);
            }
        }
    }

    /** RT-0.5、RT-2.6 型宣言のある H のメソッドでは TypeError で、関数は実行されない */
    public function testRT26TagMethodSignatureAlsoRefusesStrings(): void
    {
        foreach (['div', 'button'] as $tag) {
            try {
                H::{$tag}(onClick: self::class . '::touch');
                self::fail('Expected TypeError');
            } catch (\TypeError) {
                self::assertSame(0, self::$invoked);
            }
        }
    }

    public function testRT26TagWithoutOnClickParameterGoesThroughTheSameCheck(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::p(onClick: 'phpinfo');
    }

    public static function touch(): void
    {
        self::$invoked++;
    }
}
