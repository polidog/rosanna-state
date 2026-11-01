<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $cookies
     * @param array<string, string> $headers 小文字のヘッダー名 => 値
     */
    public function __construct(
        public string $method,
        public string $uri,
        public string $path,
        public string $queryString,
        public array $query = [],
        public array $post = [],
        public array $cookies = [],
        public array $headers = [],
        public bool $https = false,
    ) {}

    /**
     * 署名がページとして扱う単位のパス (SG-5)。host の保護はこれで比べる。
     * `$path` は `/admin/` をそのまま持つので、完全一致で比べると末尾の `/` で避けられる。
     */
    public function pagePath(): string
    {
        return self::normalizePagePath($this->path);
    }

    /** @internal ActionSigner と同じ正規化を共有するため */
    public static function normalizePagePath(string $path): string
    {
        return '/' . trim($path, '/');
    }

    public static function fromGlobals(): self
    {
        $uri = self::server('REQUEST_URI') ?? '/';
        [$path, $queryString] = self::splitUri($uri);

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (is_string($name) && is_string($value) && str_starts_with($name, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($name, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $name => $header) {
            $value = self::server($name);
            if ($value !== null) {
                $headers[$header] = $value;
            }
        }

        // `PHPSESSID[]=x` のような配列の Cookie は PHP のセッションも読まない
        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            if (is_string($value)) {
                $cookies[(string) $name] = $value;
            }
        }

        $https = self::server('HTTPS') ?? '';

        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $post */
        $post = $_POST;

        return new self(
            method: strtoupper(self::server('REQUEST_METHOD') ?? 'GET'),
            uri: $uri,
            path: $path,
            queryString: $queryString,
            query: $query,
            post: $post,
            cookies: $cookies,
            headers: $headers,
            https: $https !== '' && strtolower($https) !== 'off',
        );
    }

    /**
     * RQ-1。`//host/x` をオーソリティとして読むと、サーバーが見るパスと別のパスに署名を束縛してしまうので、
     * `/` で始まるものは parse_url() に渡さない。
     *
     * @return array{0: string, 1: string} パスとクエリ
     */
    private static function splitUri(string $uri): array
    {
        $withoutFragment = explode('#', $uri, 2)[0];

        if (str_starts_with($withoutFragment, '/')) {
            $parts = explode('?', $withoutFragment, 2);

            return ['/' . ltrim($parts[0], '/'), $parts[1] ?? ''];
        }

        // 絶対形式のリクエスト行。解釈できなければ `/` として扱う
        $parsed = parse_url($withoutFragment);
        if (!is_array($parsed)) {
            return ['/', ''];
        }

        return ['/' . ltrim($parsed['path'] ?? '', '/'), $parsed['query'] ?? ''];
    }

    private static function server(string $name): ?string
    {
        $value = $_SERVER[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
