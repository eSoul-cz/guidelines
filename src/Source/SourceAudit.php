<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Config\LockSource;
use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerCatalog;
use Esoul\Guidelines\Layer\LayerId;

/** The offline part of `check` for guideline sources: the committed copies against the config and the lock. */
final readonly class SourceAudit
{
    public function __construct(
        private string $root,
        private string $installerVersion,
    ) {
    }

    /**
     * @return list<string> one message per problem; empty when every copy is in order
     */
    public function problems(ProjectConfig $config, ?Lock $lock): array {
        $problems = [];

        foreach ($config->sources as $name => $spec) {
            $name = (string) $name; // PHP made an array key of a numeric name (`"123"`) an int
            $locked = $lock?->sources[$name] ?? null;
            $dir = LayerCatalog::copyDir($this->root, $name);
            $copy = LayerCatalog::COPY_DIR . '/' . $name . '/';

            foreach (LayerId::selected($config->layers, $name) as $layer) {
                if (CopyTree::layerFiles($dir, $layer) === []) { // the same test as the catalog: a layer is a directory with a .md file
                    $problems[] = sprintf('%s: the selected layer "%s" is missing from the copy %s. Run `esoul-guidelines sync`.', $name, LayerId::join($name, $layer), $copy);
                }
            }

            if ($lock === null) {
                continue; // the lock itself is reported by the other checks
            }
            if ($locked === null) {
                $problems[] = sprintf('%s: the source is configured in %s but not in %s. Run `esoul-guidelines sync`.', $name, ProjectConfig::FILE, Lock::FILE);

                continue;
            }
            if ($locked->url !== $spec->url || $locked->ref !== $spec->ref) {
                $problems[] = sprintf('%s: url or ref in %s (%s @ %s) differs from %s (%s @ %s). Run `esoul-guidelines sync`.', $name, ProjectConfig::FILE, $spec->url, $spec->ref, Lock::FILE, $locked->url, $locked->ref);
            }

            $problems = [...$problems, ...$this->copyProblems($name, $locked, $dir, $copy), ...$this->manifestProblems($name, $dir, $copy)];
        }

        foreach (array_keys($lock->sources ?? []) as $name) {
            $name = (string) $name;
            if ( ! isset($config->sources[$name])) {
                $problems[] = sprintf('%s: the source is in %s but no longer configured. Run `esoul-guidelines sync` to remove its copy.', $name, Lock::FILE);
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function copyProblems(string $name, LockSource $locked, string $dir, string $copy): array {
        $problems = [];
        foreach ($locked->files as $relative => $sha) {
            $actual = CopyTree::hash($dir, $relative);
            if ($actual === null) {
                $problems[] = sprintf('%s%s is missing from the copy (recorded in %s). Run `esoul-guidelines sync`.', $copy, $relative, Lock::FILE);
            } elseif ($actual !== $sha) {
                $problems[] = sprintf('%s%s was edited by hand (differs from %s). The copy is generated: revert it, or run `esoul-guidelines sync --force` to restore it.', $copy, $relative, Lock::FILE);
            }
        }
        foreach (CopyTree::files($dir) as $relative) {
            if ( ! isset($locked->files[$relative])) {
                $problems[] = sprintf('%s%s was added by hand (not in %s). The copy is generated: delete it.', $copy, $relative, Lock::FILE);
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function manifestProblems(string $name, string $dir, string $copy): array {
        if (is_link($dir) || is_link($dir . '/' . SourceManifest::FILE)) {
            return [sprintf('%s: %s%s is a symlink; the copy is generated and never holds one: run `esoul-guidelines sync --force`.', $name, $copy, SourceManifest::FILE)];
        }
        if ( ! is_file($dir . '/' . SourceManifest::FILE)) {
            return []; // reported as missing from the copy
        }

        try {
            $manifest = SourceManifest::read($dir . '/' . SourceManifest::FILE, $copy);
            $manifest->assertNamed($name, $copy);
            $manifest->assertCompatible($this->installerVersion);
        } catch (GuidelinesException $e) {
            return [sprintf('%s: %s', $name, $e->getMessage())];
        }

        return [];
    }
}
