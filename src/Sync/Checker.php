<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Config\ProjectConfig;

/** CI check: drift (block differs from the lock) and outdated (lock differs from a fresh render). */
final class Checker
{
    public function __construct(private readonly string $root) {
    }

    /**
     * @param list<Target> $targets fresh render
     */
    public function check(ProjectConfig $config, array $targets, ?Lock $lock): CheckResult {
        if ($lock === null) {
            return new CheckResult([], [sprintf('%s not found. Run `esoul-guidelines sync` and commit the lock file.', Lock::FILE)]);
        }

        $drift = [];
        $outdated = [];

        foreach ($lock->files as $path => $entry) {
            $file = $this->root . '/' . $path;
            $text = is_file($file) ? (string) file_get_contents($file) : null;
            $block = $text === null ? null : ManagedBlock::find($text, $entry->block);
            if ($block === null) {
                $drift[] = sprintf('%s: managed block "%s" is missing (expected from %s).', $path, $entry->block, Lock::FILE);
            } elseif (ManagedBlock::hash($block['content']) !== $entry->sha256) {
                $drift[] = sprintf('%s: managed block "%s" was edited locally (differs from %s). Move the changes below the block, or run `sync --force` to discard them.', $path, $entry->block, Lock::FILE);
            }
        }

        $produced = [];
        foreach ($targets as $target) {
            $produced[$target->path] = true;
            $entry = $lock->files[$target->path] ?? null;
            if ($entry === null) {
                $outdated[] = sprintf('%s: block "%s" is produced by the selected layers but is not in %s. Run `esoul-guidelines sync`.', $target->path, $target->block, Lock::FILE);
            } elseif ($entry->sha256 !== $target->hash()) {
                $outdated[] = sprintf('%s: block "%s" is outdated (a fresh render differs from the lock). Run `esoul-guidelines sync`.', $target->path, $target->block);
            }
        }
        foreach ($lock->files as $path => $entry) {
            if ( ! isset($produced[$path])) {
                $outdated[] = sprintf('%s: block "%s" is no longer produced by the selected layers. Run `esoul-guidelines sync` to remove it.', $path, $entry->block);
            }
        }
        if ($lock->layers !== $config->layers || $lock->exclude !== $config->exclude) {
            $outdated[] = sprintf('%s differs from the layers/exclusions recorded in %s. Run `esoul-guidelines sync`.', ProjectConfig::FILE, Lock::FILE);
        }

        return new CheckResult($drift, $outdated);
    }
}
