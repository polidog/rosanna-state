<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime\Support;

use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Protocol\CsrfGuard;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RendererFactory;
use Polidog\Rosanna\Runtime\RenderScope;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;

/**
 * RenderScope を直接組み立てる。Rosanna 本体を通さずに描画を確かめるため。
 */
trait RendersInScope
{
    private const SECRET = 'test-secret';

    private static function scope(
        ?string $secret = self::SECRET,
        ?CsrfGuard $csrf = null,
        string $deferPrefix = '/_defer',
        ?string $deferName = null,
        ?string $deferComponent = null,
        ?PsxRenderer $psx = null,
    ): RenderScope {
        $signer = new ActionSigner($secret);
        $serializer = $secret === null ? null : new SnapshotSerializer($secret);
        $signingScope = $deferName === null ? ActionSigner::pageScope('/page') : ActionSigner::deferScope($deferName);
        $pageSigner = $signer->forRequest(new \Polidog\Rosanna\Http\Request('GET', '/page', '/page', ''), $deferName);

        return new RenderScope(
            new RendererFactory($serializer, $pageSigner, $csrf ?? new CsrfGuard(), $deferPrefix),
            $psx ?? new PsxRenderer(),
            $serializer,
            $pageSigner,
            $signingScope,
            $deferName,
            $deferComponent,
        );
    }

    /** Element を作るところから同じスコープで動かす */
    private static function html(callable $build, ?RenderScope $scope = null, string $rootId = '_root_'): string
    {
        $scope ??= self::scope();

        return RenderContext::run($scope, static function () use ($build, $scope, $rootId): string {
            $element = $build();
            \assert($element instanceof Element || is_string($element));

            return $scope->renderers->create($rootId)->renderElement($element);
        });
    }

    private static function dom(string $html): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    }

    /**
     * @return array{action: array<string, mixed>, json: string, sig: ?string}
     */
    private static function envelope(string $value): array
    {
        $data = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        \assert(is_array($data) && is_string($data['action']));
        $action = json_decode($data['action'], true, flags: JSON_THROW_ON_ERROR);
        \assert(is_array($action));

        /** @var array{action: array<string, mixed>, json: string, sig: ?string} */
        return ['action' => $action, 'json' => $data['action'], 'sig' => $data['sig']];
    }
}
