<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** The files of a committed source copy (`.esoul/guidelines/<source>/`). */
final class CopyTree
{
    /**
     * @return list<string> paths relative to `$dir`, with `/`, sorted; empty when the directory does not exist
     */
    public static function files(string $dir): array {
        if ( ! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The `.md` files directly inside the layer directory `$dir/<layer>`, as paths relative to `$dir`.
     *
     * @return list<string>
     */
    public static function layerFiles(string $dir, string $layer): array {
        $scanned = scandir($dir . '/' . $layer);
        $names = $scanned === false ? [] : $scanned;
        $files = [];
        foreach ($names as $name) {
            if (str_ends_with($name, '.md') && is_file($dir . '/' . $layer . '/' . $name)) {
                $files[] = $layer . '/' . $name;
            }
        }
        sort($files);

        return $files;
    }

    public static function hash(string $dir, string $relative): ?string {
        $hash = is_file($dir . '/' . $relative) ? hash_file('sha256', $dir . '/' . $relative) : false;

        return $hash === false ? null : $hash;
    }

    /** The layer directory a copied file belongs to: `package/sso` for `package/sso/auth.md`; null for `source.json`. */
    public static function layerOf(string $relative): ?string {
        $slash = strrpos($relative, '/');

        return $slash === false ? null : substr($relative, 0, $slash);
    }
}
