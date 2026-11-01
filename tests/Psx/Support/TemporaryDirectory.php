<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx\Support;

final class TemporaryDirectory
{
    public static function create(string $prefix): string
    {
        $path = \sys_get_temp_dir() . '/' . $prefix . \bin2hex(\random_bytes(6));
        \mkdir($path, 0o777, true);

        return (string) \realpath($path);
    }

    public static function remove(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() && !$entry->isLink() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }
        \rmdir($path);
    }
}
