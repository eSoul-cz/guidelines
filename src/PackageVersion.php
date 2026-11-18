<?php

declare(strict_types=1);

namespace Esoul\Guidelines;

use Composer\InstalledVersions;

/** Resolves the installed package version from Composer metadata; falls back to `dev`. */
final class PackageVersion
{
    public const string PACKAGE = 'esoul-cz/guidelines';

    public static function detect(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return 'dev';
        }

        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\OutOfBoundsException) {
            return 'dev';
        }

        // A checkout without a tag reports "1.0.0+no-version-set" for the root package.
        return $version === null || str_contains($version, 'no-version-set') ? 'dev' : $version;
    }
}
