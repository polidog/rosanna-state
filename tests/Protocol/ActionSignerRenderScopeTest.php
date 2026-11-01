<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol;

use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RenderScope;

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
        $scope = new \ReflectionClass(RenderScope::class)->newInstanceWithoutConstructor();
        \Closure::bind(static function (RenderScope $scope) use ($signingScope): void {
            $scope->signingScope = $signingScope;
        }, null, RenderScope::class)($scope);

        return $scope;
    }

    public function testSG8SignOutsideAnyScopeIsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        new ActionSigner('secret')->sign(self::ID, self::JSON);
    }

    public function testSG8VerifyOutsideAnyScopeIsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        new ActionSigner('secret')->verify(self::ID, self::JSON, 'x');
    }

    public function testSG8SignUsesTheSigningScope(): void
    {
        $signer = new ActionSigner('secret', ['cart']);

        $page = RenderContext::run(self::scope('page:/p'), static fn() => $signer->sign(self::ID, self::JSON));
        self::assertSame($signer->sign(self::ID, self::JSON, '/p'), $page);

        $defer = RenderContext::run(self::scope('defer:cart'), static fn() => $signer->sign(self::ID, self::JSON));
        self::assertIsString($defer);
        self::assertTrue($signer->verify(self::ID, self::JSON, $defer, '/any'));
    }

    public function testSG8PagePathWinsOverTheScope(): void
    {
        $signer = new ActionSigner('secret');

        $signature = RenderContext::run(self::scope('page:/p'), static fn() => $signer->sign(self::ID, self::JSON, '/other'));

        self::assertSame($signer->sign(self::ID, self::JSON, '/other'), $signature);
    }
}
