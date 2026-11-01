<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Protocol\Fixtures;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\Component;
use Polidog\Rosanna\State\Storage\StorageType;

#[Component(name: 'SnapshotCounter', storage: StorageType::Snapshot, persist: 'sessionStorage')]
final class SnapshotCounter extends BaseComponent
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
