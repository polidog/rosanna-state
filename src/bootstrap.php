<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State;

use Polidog\Rosanna\Runtime\EventBinding;
use Polidog\Rosanna\Runtime\EventResolver;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\State\Storage\NativeSession;

// Composer の autoload.files が読む。リクエストごとの情報を持たないので worker でも問題ない
EventResolver::register(static fn(string $event, mixed $value): ?EventBinding => resolveEventAction($event, $value));
RequestLifecycle::onReset('rosanna-state', static function (): void {
    ComponentState::clearInstances();
    RenderPass::clear();
    NativeSession::release();
});
