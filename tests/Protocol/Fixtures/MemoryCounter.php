<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol\Fixtures;

use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Storage\StorageType;

#[Component(name: 'MemoryCounter', storage: StorageType::Memory)]
final class MemoryCounter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $set] = $this->useState(0);

        return H::div(className: 'counter', children: [
            H::span(children: 'count=' . $count),
            H::button(onClick: static fn() => $set($count + 1), children: 'inc'),
        ]);
    }
}
