<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Component;

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;

/**
 * children に置けるよう Renderable を継承する (RT-2.4、RT-5.4)。
 */
interface ComponentInterface extends Renderable
{
    public static function getComponentName(): string;

    public function render(): Element;
}
