<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

final readonly class Snapshot
{
    /**
     * @param array<int, mixed> $state
     * @param array<int, array<mixed>|null> $effectDeps
     */
    public function __construct(
        public string $componentName,
        public string $key,
        public array $state = [],
        public array $effectDeps = [],
        public int $version = 0,
        public ?string $checksum = null,
    ) {}

    public function getInstanceId(): string
    {
        return $this->componentName . '#' . $this->key;
    }

    /**
     * @throws \InvalidArgumentException JSON で往復できない値を持つとき (ST-6)
     */
    public function toJson(): string
    {
        Action::assertJsonRoundTrippable($this->state, 'state');
        Action::assertJsonRoundTrippable($this->effectDeps, 'effectDeps');

        try {
            return json_encode([
                'memo' => [
                    'name' => $this->componentName,
                    'key' => $this->key,
                    'version' => $this->version,
                ],
                'state' => $this->state,
                'effectDeps' => $this->effectDeps,
                'checksum' => $this->checksum,
            ], Action::JSON_FLAGS);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Snapshot cannot be encoded as JSON: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * 検証はしない。クライアントの入力を読むのは SnapshotSerializer::deserialize() を通す (SN-7)。
     *
     * @throws \InvalidArgumentException|\JsonException 形が不正なとき (SN-5)
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Snapshot must be a JSON object');
        }

        $memo = $data['memo'] ?? null;
        if (!is_array($memo)) {
            throw new \InvalidArgumentException('Snapshot "memo" must be an object');
        }
        $name = $memo['name'] ?? null;
        $key = $memo['key'] ?? null;
        $version = $memo['version'] ?? null;
        if (!is_string($name) || !is_string($key)) {
            throw new \InvalidArgumentException('Snapshot "memo.name" and "memo.key" must be strings');
        }
        if (!is_int($version)) {
            throw new \InvalidArgumentException('Snapshot "memo.version" must be an integer');
        }

        $state = $data['state'] ?? [];
        $effectDeps = $data['effectDeps'] ?? [];
        if (!is_array($state) || !self::hasIntKeys($state)) {
            throw new \InvalidArgumentException('Snapshot "state" must be indexed by hook');
        }
        if (!is_array($effectDeps) || !self::hasIntKeys($effectDeps)) {
            throw new \InvalidArgumentException('Snapshot "effectDeps" must be indexed by hook');
        }
        foreach ($effectDeps as $deps) {
            if ($deps !== null && !is_array($deps)) {
                throw new \InvalidArgumentException('Snapshot "effectDeps" values must be arrays or null');
            }
        }

        $checksum = $data['checksum'] ?? null;
        if ($checksum !== null && !is_string($checksum)) {
            throw new \InvalidArgumentException('Snapshot "checksum" must be a string');
        }

        /** @var array<int, mixed> $state */
        /** @var array<int, array<mixed>|null> $effectDeps */
        return new self($name, $key, $state, $effectDeps, $version, $checksum);
    }

    /** @param array<mixed> $values */
    private static function hasIntKeys(array $values): bool
    {
        foreach (array_keys($values) as $key) {
            if (!is_int($key)) {
                return false;
            }
        }

        return true;
    }
}
