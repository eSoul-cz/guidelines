<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Detect;

/** Detects guideline layers from `composer.json` and `package.json` dependencies. */
final class StackDetector
{
    /** @var array<string, string> composer package => layer */
    private const array COMPOSER = [
        'laravel/framework' => 'framework/laravel',
        'lsr/core' => 'framework/lsr',
        'lsr/cqrs' => 'package/lsr-cqrs',
        'filament/filament' => 'package/filament',
        'lunarphp/lunar' => 'package/lunar',
        'infection/infection' => 'package/mutation-testing',
        'esoul-cz/phpunit-test-evidence' => 'package/test-evidence',
    ];

    /** @var array<string, list<string>> layer => composer packages that must all be required */
    private const array COMPOSER_COMBINED = [
        'package/laravel-inertia' => ['laravel/framework', 'inertiajs/inertia-laravel'],
    ];

    /** @var array<string, string> npm package => layer */
    private const array NPM = [
        'astro' => 'framework/astro',
        '@inertiajs/vue3' => 'package/inertia-vue',
    ];

    /** @var array<string, string> layer => layer it brings along (Lunar's admin is a Filament panel, though the project never requires Filament directly) */
    private const array IMPLIES = [
        'package/lunar' => 'package/filament',
    ];

    /**
     * @return list<string> detected layers (without `general`), frameworks first
     */
    public function detect(string $root): array {
        $found = [];
        $composer = $this->dependencies($root . '/composer.json', ['require', 'require-dev']);
        foreach ($composer as $package) {
            if (isset(self::COMPOSER[$package])) {
                $found[self::COMPOSER[$package]] = true;
            }
        }
        foreach (self::COMPOSER_COMBINED as $layer => $packages) {
            if (array_diff($packages, $composer) === []) {
                $found[$layer] = true;
            }
        }
        foreach ($this->dependencies($root . '/package.json', ['dependencies', 'devDependencies']) as $package) {
            if (isset(self::NPM[$package])) {
                $found[self::NPM[$package]] = true;
            }
        }

        foreach (self::IMPLIES as $layer => $implied) {
            if (isset($found[$layer])) {
                $found[$implied] = true;
            }
        }

        $layers = array_keys($found);
        usort($layers, static fn (string $a, string $b): int => [str_starts_with($a, 'framework/') ? 0 : 1, $a] <=> [str_starts_with($b, 'framework/') ? 0 : 1, $b]);

        return $layers;
    }

    /**
     * @param list<string> $sections
     * @return list<string>
     */
    private function dependencies(string $file, array $sections): array {
        if ( ! is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if ( ! is_array($data)) {
            return [];
        }

        $names = [];
        foreach ($sections as $section) {
            if (is_array($data[$section] ?? null)) {
                array_push($names, ...array_map('strval', array_keys($data[$section])));
            }
        }

        return $names;
    }
}
