<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Protocol;

use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Storage\NativeSession;

/**
 * Origin の比較とセッションのトークンの 2 層 (CS-2、CS-3)。リクエストの情報は持たない (RQ-3)。
 */
final class CsrfGuard
{
    public const FIELD = '_rosanna_csrf';

    private const SESSION_KEY = '_rosanna_csrf';

    public function __construct(
        private readonly bool $enabled = true,
        private readonly bool $trustProxyHeaders = false,
    ) {}

    /**
     * 無効またはセッションなしなら ''。セッションは描画の途中で始まることがあるので、フォームを出すたびに読む (CS-6)。
     */
    public function token(): string
    {
        if (!$this->enabled || !NativeSession::isActive()) {
            return '';
        }

        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    /**
     * Cookie からセッションを再開する (CS-1)。署名の検証もセッションに依るので、無効化されていても動かす。
     */
    public function resumeSession(Request $request): void
    {
        NativeSession::resumeFromCookie($request->cookies);
    }

    /**
     * null は成功。文字列はログ用の拒否理由で、クライアントの入力を含まない (CS-8)。
     */
    public function verify(Request $request): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $expected = $this->expectedOrigin($request);
        if ($expected === null) {
            return 'CSRF check failed: cannot determine the expected origin';
        }

        $source = self::sourceOrigin($request);
        if ($source === null) {
            return 'CSRF check failed: request has no Origin or Referer';
        }
        if (!hash_equals($expected, $source)) {
            return 'CSRF check failed: source origin does not match this host';
        }

        $this->resumeSession($request);
        if (!NativeSession::isActive()) {
            // CS-4、CS-5 Cookie がなければ攻撃する状態がない。あるのに開始できないなら第 2 層を外させない
            return NativeSession::hasCookie($request->cookies)
                ? 'CSRF check failed: request has a session cookie but the session could not be started'
                : null;
        }

        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;
        $postToken = $request->post[self::FIELD] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '' || !is_string($postToken) || !hash_equals($sessionToken, $postToken)) {
            return 'CSRF check failed: token missing or mismatched';
        }

        return null;
    }

    private function expectedOrigin(Request $request): ?string
    {
        $host = $this->forwarded($request, 'x-forwarded-host') ?? ($request->headers['host'] ?? '');
        if ($host === '') {
            return null;
        }

        $scheme = $this->expectedScheme($request);
        $origin = $scheme . '://' . $host;

        // `[::1]` のコロンを port と見なさない
        if ($this->trustProxyHeaders && preg_match('/:\d+$/', $host) !== 1) {
            $port = $this->forwarded($request, 'x-forwarded-port');
            if ($port !== null && ctype_digit($port)) {
                $origin .= ':' . $port;
            }
        }

        return self::canonicalOrigin($origin);
    }

    private function expectedScheme(Request $request): string
    {
        $proto = $this->forwarded($request, 'x-forwarded-proto');
        if ($proto !== null) {
            return strtolower($proto) === 'https' ? 'https' : 'http';
        }

        return $request->https ? 'https' : 'http';
    }

    /** CS-7 信じる設定のときだけ読み、カンマ区切りの先頭を使う */
    private function forwarded(Request $request, string $header): ?string
    {
        if (!$this->trustProxyHeaders) {
            return null;
        }
        $value = trim(explode(',', $request->headers[$header] ?? '')[0]);

        return $value === '' ? null : $value;
    }

    private static function sourceOrigin(Request $request): ?string
    {
        $origin = $request->headers['origin'] ?? '';
        if ($origin !== '' && $origin !== 'null') {
            return self::canonicalOrigin($origin);
        }

        $referer = $request->headers['referer'] ?? '';
        // parse_url() は `\` を host の区切りと見なさず、ブラウザと読み方が違う
        if ($referer === '' || preg_match('/[\\\\\x00-\x20\x7F]/', $referer) === 1) {
            return null;
        }

        $parsed = parse_url($referer);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return self::canonicalOrigin($parsed['scheme'] . '://' . $parsed['host'] . $port);
    }

    /** scheme と host の大文字小文字、scheme の既定の port は同じオリジンとして扱う */
    private static function canonicalOrigin(string $origin): string
    {
        $origin = strtolower($origin);

        if (str_starts_with($origin, 'http://') && str_ends_with($origin, ':80')) {
            return substr($origin, 0, -3);
        }
        if (str_starts_with($origin, 'https://') && str_ends_with($origin, ':443')) {
            return substr($origin, 0, -4);
        }

        return $origin;
    }
}
