<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Storage\StorageType;

final readonly class Action
{
    public const SET_STATE = 'setState';
    public const SET_FIELDS = 'setFields';
    public const RESTORE = 'restore';

    /**
     * 往復で形を変えず、不正な UTF-8 で出力を空にしない。署名の前後で一定に保つ (SN-2、AC-15)。
     *
     * @internal
     */
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /** json_encode() と json_decode() の既定の深さ */
    private const MAX_JSON_DEPTH = 512;

    private const MAX_HOOK_INDEX = 255;

    private const MAX_INSTANCE_ID_BYTES = 255;

    /** @param array<array-key, mixed> $payload */
    public function __construct(
        public string $type,
        public array $payload = [],
        public ?string $componentId = null,
        public ?StorageType $storageType = null,
        public ?int $version = null,
    ) {}

    public static function setState(int $stateIndex, mixed $value, ?string $componentId = null, ?StorageType $storageType = null): self
    {
        return new self(self::SET_STATE, ['index' => $stateIndex, 'value' => $value], $componentId, $storageType);
    }

    /** @param array<mixed> $patch StateDiff::create() の戻り値 */
    public static function setStateDiff(int $stateIndex, array $patch, string $base, ?string $componentId = null, ?StorageType $storageType = null): self
    {
        return new self(self::SET_STATE, ['index' => $stateIndex, 'patch' => $patch, 'base' => $base], $componentId, $storageType);
    }

    /** @param array<string, int> $fields フィールド名 => フックの index */
    public static function setFields(array $fields, ?string $componentId = null, ?StorageType $storageType = null): self
    {
        return new self(self::SET_FIELDS, ['fields' => $fields], $componentId, $storageType);
    }

    public static function restore(?string $componentId = null, ?StorageType $storageType = null): self
    {
        return new self(self::RESTORE, [], $componentId, $storageType);
    }

    public function withVersion(int $version): self
    {
        return new self($this->type, $this->payload, $this->componentId, $this->storageType, $version);
    }

    /** @throws \InvalidArgumentException JSON で往復できない値を持つとき (AC-4) */
    public function toJson(): string
    {
        self::assertJsonRoundTrippable($this->payload);

        try {
            return json_encode([
                'type' => $this->type,
                'payload' => $this->payload,
                'componentId' => $this->componentId,
                'storageType' => $this->storageType?->value,
                'version' => $this->version,
            ], self::JSON_FLAGS);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Action cannot be encoded as JSON: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * クライアントの入力を受ける。型をすべて確かめ、TypeError にしない (AC-3)。
     * AC-1、AC-5、AC-6 もここで拒否するので、未知の種類や範囲外の index は Action にならない。
     *
     * @param array<mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new \InvalidArgumentException('Action "type" must be a non-empty string');
        }

        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Action "payload" must be an object');
        }
        self::assertPayload($type, $payload);

        $componentId = $data['componentId'] ?? null;
        if ($componentId !== null && (!is_string($componentId) || !self::isValidInstanceId($componentId))) {
            throw new \InvalidArgumentException('Action "componentId" must be a valid instance ID or null');
        }

        $storageType = null;
        if (($data['storageType'] ?? null) !== null) {
            $storageType = is_string($data['storageType']) ? StorageType::tryFrom($data['storageType']) : null;
            if ($storageType === null) {
                throw new \InvalidArgumentException('Action "storageType" is not a known storage type');
            }
        }

        $version = $data['version'] ?? null;
        if ($version !== null && !is_int($version)) {
            throw new \InvalidArgumentException('Action "version" must be an integer or null');
        }

        return new self($type, $payload, $componentId, $storageType, $version);
    }

    /**
     * 値が往復で形を変えないことを確かめる (AC-4)。循環は深さの上限で止まる。
     *
     * @throws \InvalidArgumentException
     */
    public static function assertJsonRoundTrippable(mixed $value, string $path = '', int $depth = 0): void
    {
        if (is_array($value)) {
            if ($depth >= self::MAX_JSON_DEPTH) {
                throw new \InvalidArgumentException(sprintf(
                    'State value at "%s" is nested more than %d levels deep or references itself.',
                    $path,
                    self::MAX_JSON_DEPTH,
                ));
            }
            foreach ($value as $key => $item) {
                self::assertJsonRoundTrippable($item, $path === '' ? (string) $key : $path . '.' . $key, $depth + 1);
            }

            return;
        }

        if ($value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value))) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'State value at "%s" is %s, which does not survive the JSON round trip between renders. '
            . 'Only null, scalars and arrays of those are allowed.',
            $path,
            is_float($value) ? var_export($value, true) : get_debug_type($value),
        ));
    }

    /**
     * AC-6。署名が束縛するので、ストレージのキーとログを守るだけ。`_rosanna_component` の検査にも使う。
     */
    public static function isValidInstanceId(string $instanceId): bool
    {
        return strlen($instanceId) <= self::MAX_INSTANCE_ID_BYTES
            && str_contains($instanceId, '#')
            && preg_match('/[\x00-\x1F\x7F]/', $instanceId) !== 1;
    }

    /**
     * @param array<mixed> $payload
     * @throws \InvalidArgumentException
     */
    private static function assertPayload(string $type, array $payload): void
    {
        switch ($type) {
            case self::SET_STATE:
                self::assertHookIndex($payload['index'] ?? null);
                $hasValue = array_key_exists('value', $payload);
                $hasPatch = array_key_exists('patch', $payload) || array_key_exists('base', $payload);
                if ($hasValue === $hasPatch) {
                    throw new \InvalidArgumentException('Action "setState" must carry either "value" or "patch" and "base"');
                }
                if ($hasPatch && (!is_array($payload['patch'] ?? null) || !is_string($payload['base'] ?? null))) {
                    throw new \InvalidArgumentException('Action "setState" diff must carry an array "patch" and a string "base"');
                }
                break;

            case self::SET_FIELDS:
                $fields = $payload['fields'] ?? null;
                if (!is_array($fields) || $fields === []) {
                    throw new \InvalidArgumentException('Action "setFields" must carry a non-empty "fields" map');
                }
                foreach ($fields as $name => $index) {
                    if (!is_string($name)) {
                        throw new \InvalidArgumentException('Action "fields" names must be strings');
                    }
                    self::assertHookIndex($index);
                }
                break;

            case self::RESTORE:
                if ($payload !== []) {
                    throw new \InvalidArgumentException('Action "restore" must have an empty payload');
                }
                break;

            default:
                throw new \InvalidArgumentException('Action "type" is not a known action type');
        }
    }

    /** @throws \InvalidArgumentException */
    private static function assertHookIndex(mixed $index): void
    {
        if (!is_int($index) || $index < 0 || $index > self::MAX_HOOK_INDEX) {
            throw new \InvalidArgumentException('Action hook index must be an integer from 0 to ' . self::MAX_HOOK_INDEX);
        }
    }
}
