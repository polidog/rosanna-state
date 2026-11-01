<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Protocol;

/**
 * `_rosanna_action` の値。actionJson は署名の対象のバイト列なので文字列のまま運ぶ (AC-20)。
 */
final readonly class ActionEnvelope
{
    public function __construct(public string $actionJson, public ?string $signature) {}

    /** @throws \InvalidArgumentException actionJson が JSON の文字列にできず、バイト列を保てないとき */
    public function toJson(): string
    {
        try {
            return json_encode(
                ['action' => $this->actionJson, 'sig' => $this->signature],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Action envelope cannot be encoded: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * JSON オブジェクトでなければ null (AC-22)。Relayer のトークンはここを通って host に渡る。
     *
     * @throws \InvalidArgumentException オブジェクトで形が不正なとき
     */
    public static function decode(string $json): ?self
    {
        // 連想配列で読むと `{}` と `[]` を区別できない
        $data = json_decode($json);
        if (!$data instanceof \stdClass) {
            return null;
        }

        $action = $data->action ?? null;
        if (!is_string($action) || $action === '') {
            throw new \InvalidArgumentException('Action envelope "action" must be a non-empty string');
        }
        if (!property_exists($data, 'sig') || ($data->sig !== null && !is_string($data->sig))) {
            throw new \InvalidArgumentException('Action envelope "sig" must be a string or null');
        }

        return new self($action, $data->sig);
    }
}
