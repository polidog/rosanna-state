<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Protocol;

use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RenderScope;
use Polidog\Rosanna\State\Protocol\ActionSigner;
use Polidog\Rosanna\State\Protocol\CsrfGuard;
use Polidog\Rosanna\State\RendererFactory;
use Polidog\Rosanna\State\StateScope;

/**
 * SG-8。束縛のない signer は現在の RenderScope の signingScope を使う。
 * RenderContext は別の担当が作るので、できるまではスキップとして見える。
 */
#[RequiresMethod(RenderContext::class, 'currentScope')]
final class ActionSignerRenderScopeTest extends TestCase
{
    private const ID = 'Counter#0';
    private const JSON = '{"type":"restore"}';

    private static function scope(string $signingScope): RenderScope
    {
        $signer = new ActionSigner('secret-fixture-key-1');
        $psx = new PsxRenderer();

        return new RenderScope($psx, new StateScope(new RendererFactory(null, $signer, new CsrfGuard()), $psx, null, $signer, $signingScope));
    }

    public function testSG8SignOutsideAnyScopeIsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        new ActionSigner('secret-fixture-key-1')->sign(self::ID, self::JSON);
    }

    public function testSG8VerifyOutsideAnyScopeIsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        new ActionSigner('secret-fixture-key-1')->verify(self::ID, self::JSON, 'x');
    }

    public function testSG8SignUsesTheSigningScope(): void
    {
        $signer = new ActionSigner('secret-fixture-key-1', ['cart']);

        $page = RenderContext::run(self::scope('page:/p'), static fn() => $signer->sign(self::ID, self::JSON));
        self::assertSame($signer->sign(self::ID, self::JSON, '/p'), $page);

        $defer = RenderContext::run(self::scope('defer:cart'), static fn() => $signer->sign(self::ID, self::JSON));
        self::assertIsString($defer);
        self::assertTrue($signer->verify(self::ID, self::JSON, $defer, '/any'));
    }

    public function testSG8PagePathWinsOverTheScope(): void
    {
        $signer = new ActionSigner('secret-fixture-key-1');

        $signature = RenderContext::run(self::scope('page:/p'), static fn() => $signer->sign(self::ID, self::JSON, '/other'));

        self::assertSame($signer->sign(self::ID, self::JSON, '/other'), $signature);
    }
}
