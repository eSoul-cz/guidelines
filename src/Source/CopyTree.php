<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The files of a committed source copy (`.esoul/guidelines/<source>/`), or of a fetched checkout.
 *
 * A symlink is never followed: it could point anywhere on the machine (a private key, say) and put that file into the
 * copy, which is committed. A symlink is no file here; {@see files()} lists it so that it is reported, and
 * {@see symlinks()} finds the ones a checkout brings.
 */
final class CopyTree
{
    /**
     * @return list<string> paths (files and symlinks) relative to `$dir`, with `/`, sorted; empty when the directory does not exist
     */
    public static function files(string $dir): array {
        $files = [];
        foreach (self::entries($dir) as $file) {
            if ($file->isLink() || $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string> the symlinks anywhere under `$dir` (directories and files), relative to it, sorted
     */
    public static function symlinks(string $dir): array {
        $links = [];
        foreach (self::entries($dir) as $file) {
            if ($file->isLink()) {
                $links[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        sort($links);

        return $links;
    }

    /**
     * The `.md` files directly inside the layer directory `$dir/<layer>`, as paths relative to `$dir`.
     *
     * @return list<string>
     */
    public static function layerFiles(string $dir, string $layer): array {
        if ( ! self::isPlain($dir, $layer, true)) {
            return [];
        }
        $scanned = scandir($dir . '/' . $layer);
        $names = $scanned === false ? [] : $scanned;
        $files = [];
        foreach ($names as $name) {
            if (str_ends_with($name, '.md') && self::isPlain($dir, $layer . '/' . $name, false)) {
                $files[] = $layer . '/' . $name;
            }
        }
        sort($files);

        return $files;
    }

    /** The sha256 of a regular file; null when it is missing, is a symlink, or lies behind a symlinked directory. */
    public static function hash(string $dir, string $relative): ?string {
        $hash = self::isPlain($dir, $relative, false) ? hash_file('sha256', $dir . '/' . $relative) : false;

        return $hash === false ? null : $hash;
    }

    /** The layer directory a copied file belongs to: `package/sso` for `package/sso/auth.md`; null for `source.json`. */
    public static function layerOf(string $relative): ?string {
        $slash = strrpos($relative, '/');

        return $slash === false ? null : substr($relative, 0, $slash);
    }

    /**
     * @return iterable<SplFileInfo> every entry under `$dir`; symlinked directories are listed, not entered
     */
    private static function entries(string $dir): iterable {
        if (is_link($dir) || ! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo) {
                yield $file;
            }
        }
    }

    /** Whether `$dir/$relative` is a regular file (or directory) with no symlink in `$dir` or on the way to it. */
    public static function isPlain(string $dir, string $relative, bool $directory): bool {
        if (is_link($dir)) {
            return false;
        }
        $path = $dir;
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) {
                return false;
            }
        }

        return $directory ? is_dir($path) : is_file($path);
    }
}
