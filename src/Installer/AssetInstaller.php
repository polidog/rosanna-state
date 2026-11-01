<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Installer;

use Composer\Script\Event;

/**
 * rosanna.js をプロジェクトの public/ に配置する Composer スクリプト。
 * Event の型は Composer の中でしか解決されない (RT-13.6)。
 */
final class AssetInstaller
{
    /** 失敗は警告にとどめる。`composer install` を止めないため (RT-13.5) */
    public static function publish(Event $event): void
    {
        $io = $event->getIO();

        try {
            $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
            if (!is_string($vendorDir) || $vendorDir === '') {
                $io->writeError('<warning>Rosanna: could not determine the vendor directory</warning>');

                return;
            }

            $source = dirname(__DIR__, 2) . '/public/rosanna.js';
            $targetDir = dirname($vendorDir) . '/public';
            $target = $targetDir . '/rosanna.js';

            if (!is_file($source)) {
                $io->writeError('<warning>Rosanna: rosanna.js source not found</warning>');

                return;
            }
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
                $io->writeError('<warning>Rosanna: could not create the public directory</warning>');

                return;
            }
            if (!@copy($source, $target)) {
                $io->writeError('<warning>Rosanna: could not copy rosanna.js</warning>');

                return;
            }

            $io->write('<info>Rosanna:</info> Published rosanna.js to public/rosanna.js');
        } catch (\Throwable $e) {
            $io->writeError('<warning>Rosanna: could not publish rosanna.js: ' . $e->getMessage() . '</warning>');
        }
    }
}
