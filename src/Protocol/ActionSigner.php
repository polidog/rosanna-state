<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Protocol;

use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Storage\NativeSession;

/**
 * action をサーバーが描画したものだけに限る署名 (AC、SG)。
 *
 * リクエストの情報は持たない。`forRequest()` が返す別のインスタンスに束縛するので、Rosanna 本体が持つものには残らない (RQ-3)。
 * 束縛がなければ現在の RenderScope の signingScope を使う (SG-8)。グローバルの REQUEST_URI は読まない。
 */
final class ActionSigner
{
    private const SESSION_KEY = '_rosanna_action_key';

    private ?string $boundScope = null;

    private ?string $boundPageScope = null;

    /**
     * @param string|null $secretKey 秘密鍵。null ならセッションごとの鍵を使う (SG-2)
     * @param list<string> $deferNames 登録された defer の名前。受け付ける範囲に入る (SG-6)
     * @throws \InvalidArgumentException 鍵が空文字のとき
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly ?string $secretKey = null,
        private readonly array $deferNames = [],
    ) {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('ActionSigner requires a non-empty secret key, or null for a per-session key.');
        }
    }

    /**
     * このリクエストに束縛した新しいインスタンスを返す。元のインスタンスは変えない。
     * $deferName があれば、描画中の署名の scope を defer:{名前} にする (DF-10)。検証の範囲は同じ。
     */
    public function forRequest(Request $request, ?string $deferName = null): self
    {
        $signer = clone $this;
        $signer->boundPageScope = self::pageScope($request->path);
        $signer->boundScope = $deferName !== null ? self::deferScope($deferName) : $signer->boundPageScope;

        return $signer;
    }

    /** `RenderScope::$signingScope` に入れる値。束縛がなければ null */
    public function signingScope(): ?string
    {
        return $this->boundScope;
    }

    public static function pageScope(string $path): string
    {
        return 'page:' . Request::normalizePagePath($path);
    }

    public static function deferScope(string $name): string
    {
        return 'defer:' . $name;
    }

    /**
     * 鍵がなければ null。$pagePath で別のページを指定できる。
     *
     * @throws \LogicException $pagePath も束縛も描画スコープもなく、scope を決められないとき
     */
    public function sign(string $componentId, string $actionJson, ?string $pagePath = null): ?string
    {
        // SG-3 検証と同じセッションの下で署名する
        NativeSession::resumeFromCookie();
        if ($this->secretKey === null) {
            NativeSession::start();
        }

        $scope = $pagePath !== null ? self::pageScope($pagePath) : $this->ambientScope();
        if ($scope === null) {
            throw new \LogicException('ActionSigner::sign() needs a page path, forRequest() or a render scope.');
        }

        $key = $this->secretKey ?? $this->sessionKey(true);

        return $key === null ? null : hash_hmac('sha256', self::payload($scope, $componentId, $actionJson), $key);
    }

    /**
     * セッションを開始せず、鍵も作らない (SG-4)。受け付ける範囲はそのリクエストのページと、登録された全 defer (SG-6)。
     * リクエストのどのフィールドも scope を選ばない (SG-7)。
     *
     * @param string|null $pagePath host が `handle()` に渡したパス。なければ束縛か描画スコープを使う (SG-8)
     * @throws \LogicException ページを決められないとき
     */
    public function verify(string $componentId, string $actionJson, string $signature, ?string $pagePath = null): bool
    {
        $pageScope = $pagePath !== null ? self::pageScope($pagePath) : ($this->boundPageScope ?? $this->ambientScope());
        if ($pageScope === null) {
            throw new \LogicException('ActionSigner::verify() needs a page path, forRequest() or a render scope.');
        }

        $key = $this->secretKey ?? $this->sessionKey(false);
        // 区切りの NUL をずらして別の入力に見せかけるのを防ぐ
        if ($key === null || str_contains($componentId . $pageScope, "\0")) {
            return false;
        }

        $scopes = [$pageScope];
        foreach ($this->deferNames as $name) {
            $scopes[] = self::deferScope($name);
        }

        foreach ($scopes as $scope) {
            if (hash_equals(hash_hmac('sha256', self::payload($scope, $componentId, $actionJson), $key), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function ambientScope(): ?string
    {
        return $this->boundScope ?? RenderContext::currentScope()?->signingScope;
    }

    private function sessionKey(bool $create): ?string
    {
        if (!NativeSession::isActive()) {
            return null;
        }

        $key = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($key) && $key !== '') {
            return $key;
        }
        if (!$create) {
            return null;
        }

        return $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    private static function payload(string $scope, string $componentId, string $actionJson): string
    {
        $sessionId = NativeSession::isActive() ? session_id() : '';

        return "rosanna-action\0{$scope}\0{$sessionId}\0{$componentId}\0{$actionJson}";
    }
}
