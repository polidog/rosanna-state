<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Storage\StorageType;

/**
 * 書き出す前のフォーム。CSRF トークンとスナップショットは書き出すときに読むので、ここには持たない (RT-4.6)。
 *
 * @internal
 */
final class ActionForm
{
    public bool $written = false;

    /** @var array<int, true> `_rosanna_base[{index}]` を出すフックの位置 (AC-18) */
    public array $baseIndexes = [];

    /** @param string|null $envelope 個別フォームの `_rosanna_action`。共有フォームは null (RT-4.7、RT-4.9) */
    public function __construct(
        public readonly string $id,
        public readonly string $componentId,
        public readonly ?StorageType $storageType,
        public readonly ?string $envelope = null,
    ) {}
}
