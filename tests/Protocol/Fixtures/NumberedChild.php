<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Protocol\Fixtures;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\Component;

#[Component(name: 'NumberedChild')]
final class NumberedChild extends BaseComponent
{
    public function render(): Element
    {
        return H::span(children: 'class-child');
    }
}
