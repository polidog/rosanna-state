<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol\Fixtures;

use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Storage\StorageType;

/** パラメーターを $_GET から読むクラスコンポーネント (HD-11) */
#[Component(name: 'DeferredPanel', storage: StorageType::Memory)]
#[Defer('panel', cacheControl: 'private, max-age=30')]
final class DeferredPanel extends BaseComponent
{
    public function render(): Element
    {
        [$count, $set] = $this->useState(0);
        $topic = $_GET['topic'] ?? 'none';

        return H::div(children: [
            H::span(children: 'topic=' . (is_string($topic) ? $topic : 'array') . ' count=' . $count),
            H::button(onClick: static fn() => $set($count + 1), children: 'inc'),
        ]);
    }
}
