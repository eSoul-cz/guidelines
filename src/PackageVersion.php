<?php

declare(strict_types=1);

namespace Esoul\Guidelines;

use Composer\InstalledVersions;
use OutOfBoundsException;

/**
 * Resolves the installed version: from the `resources/VERSION` stamp inside the PHAR (written by `scripts/build-phar`),
 * otherwise from Composer metadata; falls back to `dev`.
 */
final class PackageVersion
{
    public const string PACKAGE = 'esoul-cz/guidelines';

    public static function detect(): string {
        if (str_starts_with(__FILE__, 'phar://')) {
            return self::fromStamp(dirname(__DIR__) . '/resources/VERSION');
        }

        if ( ! class_exists(InstalledVersions::class)) {
            return 'dev';
        }

        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (OutOfBoundsException) {
            return 'dev';
        }

        // A checkout without a tag reports "1.0.0+no-version-set" for the root package.
        return $version === null || str_contains($version, 'no-version-set') ? 'dev' : $version;
    }

    /** The version written into a stamp file; `dev` when the file is missing or empty. */
    public static function fromStamp(string $file): string {
        $stamp = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $stamp === '' ? 'dev' : $stamp;
    }
}
