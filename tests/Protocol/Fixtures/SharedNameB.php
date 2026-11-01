<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol\Fixtures;

use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Storage\StorageType;

/** 別の defer の中で同じインスタンス ID になるコンポーネント (HD-9) */
#[Component(name: 'Shared', storage: StorageType::Memory)]
final class SharedNameB extends BaseComponent
{
    public function render(): Element
    {
        [$count, $set] = $this->useState(0);
        $topic = $_GET['topic'] ?? 'none';

        return H::div(children: [
            H::span(children: 'from-B ' . $count . ' topic=' . (is_string($topic) ? $topic : 'array')),
            H::button(onClick: static fn() => $set($count + 1), children: 'inc'),
        ]);
    }
}
