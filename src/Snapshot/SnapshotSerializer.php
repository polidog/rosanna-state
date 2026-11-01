<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Snapshot;

use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\Snapshot;

/**
 * スナップショットの署名と検証。期限はなく、鍵か、文脈つきならセッション ID が変わるまで有効 (SN-8)。
 */
final class SnapshotSerializer
{
    /** @throws \InvalidArgumentException 鍵が空のとき (SN-4) */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secretKey,
        private readonly string $context = '',
    ) {
        if ($secretKey === '') {
            throw new \InvalidArgumentException(
                'SnapshotSerializer requires a non-empty secret key. Generate one with bin2hex(random_bytes(32)).',
            );
        }
    }

    /** @throws \InvalidArgumentException JSON で往復できない値を持つとき */
    public function serialize(Snapshot $snapshot): string
    {
        return new Snapshot(
            $snapshot->componentName,
            $snapshot->key,
            $snapshot->state,
            $snapshot->effectDeps,
            $snapshot->version,
            $this->checksum($snapshot),
        )->toJson();
    }

    /**
     * 入力はクライアントから来るので、壊れた形も署名の失敗と同じ例外にする (SN-5)。
     *
     * @throws SnapshotVerificationException
     */
    public function deserialize(string $json): Snapshot
    {
        try {
            $snapshot = Snapshot::fromJson($json);
            $expected = $this->checksum($snapshot);
        } catch (\JsonException|\InvalidArgumentException $e) {
            throw new SnapshotVerificationException('Snapshot is malformed', previous: $e);
        }

        if ($snapshot->checksum === null || !hash_equals($expected, $snapshot->checksum)) {
            throw new SnapshotVerificationException('Snapshot checksum verification failed');
        }

        return $snapshot;
    }

    /**
     * 空の文脈でも長さつきの接頭辞を付ける。生の文字列を署名すると、空の文脈の `ctx:1:a:b` と文脈 `a` の `b` が
     * 同じ入力になり、スナップショットの JSON の checksum とも重なる (SN-6)。
     */
    public function signString(string $payload): string
    {
        return hash_hmac('sha256', sprintf('ctx:%d:%s:%s', strlen($this->context), $this->context, $payload), $this->secretKey);
    }

    public function verifyString(string $payload, string $signature): bool
    {
        return hash_equals($this->signString($payload), $signature);
    }

    public function withContext(string $context): self
    {
        return new self($this->secretKey, $context);
    }

    /** @throws \InvalidArgumentException */
    private function checksum(Snapshot $snapshot): string
    {
        $payload = [
            'name' => $snapshot->componentName,
            'key' => $snapshot->key,
            'version' => $snapshot->version,
            'state' => $snapshot->state,
            'effectDeps' => $snapshot->effectDeps,
        ];
        if ($this->context !== '') {
            $payload = ['ctx' => $this->context] + $payload;
        }

        try {
            return hash_hmac('sha256', json_encode($payload, Action::JSON_FLAGS), $this->secretKey);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Snapshot cannot be encoded as JSON: ' . $e->getMessage(), previous: $e);
        }
    }
}
