<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Protocol\Fixtures;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\Component;

use function Polidog\Rosanna\State\fc;

/** key のない子を 2 種類持つ。fc はその場で、クラスのインスタンスは描画のときに番号を取る (RT-8.6) */
#[Component(name: 'NumberedParent')]
final class NumberedParent extends BaseComponent
{
    public function render(): Element
    {
        [$count, $set] = $this->useState(0);
        $child = fc(static fn(): Element => H::span(children: 'fc-child'));

        return H::div(children: [
            H::button(onClick: static fn() => $set($count + 1), children: "parent={$count}"),
            $child(),
            new NumberedChild(),
        ]);
    }
}
