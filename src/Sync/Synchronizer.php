<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Config\LockEntry;
use Esoul\Guidelines\Config\ProjectConfig;
use Symfony\Component\Filesystem\Filesystem;

/** Plans and applies the writes of managed blocks and the lock file. */
final class Synchronizer
{
    public const string CLAUDE_FILE = 'CLAUDE.md';

    private readonly Filesystem $fs;

    public function __construct(
        private readonly string $root,
        private readonly string $packageVersion,
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * @param list<Target> $targets
     */
    public function plan(array $targets, ?Lock $lock): Plan
    {
        $changes = [];
        $produced = [];

        foreach ($targets as $target) {
            $produced[$target->path] = true;
            $found = $this->currentBlock($target->path, $target->block);
            $entry = $lock?->files[$target->path] ?? null;

            if ($found === null) {
                $changes[] = new Change(Status::Create, $target->path, $target->block, $target->kind, null, $target->content, $entry !== null ? 'managed block was removed locally; it will be recreated' : '');
                continue;
            }

            $currentHash = ManagedBlock::hash($found);
            if ($currentHash === $target->hash()) {
                $changes[] = new Change(Status::Current, $target->path, $target->block, $target->kind, $found, $target->content);
            } elseif ($entry !== null && $entry->block === $target->block && $entry->sha256 === $currentHash) {
                $changes[] = new Change(Status::Update, $target->path, $target->block, $target->kind, $found, $target->content);
            } else {
                $note = $entry === null ? 'not tracked in ' . Lock::FILE . '; cannot tell local edits from outdated content' : 'edited locally since the last sync';
                $changes[] = new Change(Status::Drifted, $target->path, $target->block, $target->kind, $found, $target->content, $note);
            }
        }

        foreach ($lock?->files ?? [] as $path => $entry) {
            if (isset($produced[$path])) {
                continue;
            }
            $found = $this->currentBlock($path, $entry->block);
            if ($found === null) {
                $changes[] = new Change(Status::Forget, $path, $entry->block, Target::KIND_GUIDELINE, null, null, 'block already gone');
            } elseif (ManagedBlock::hash($found) === $entry->sha256) {
                $changes[] = new Change(Status::Remove, $path, $entry->block, Target::KIND_GUIDELINE, $found, null, 'no longer produced by the selected layers');
            } else {
                $changes[] = new Change(Status::RemoveDrifted, $path, $entry->block, Target::KIND_GUIDELINE, $found, null, 'no longer produced, but the block has local edits');
            }
        }

        return new Plan($changes, !is_file($this->root . '/' . self::CLAUDE_FILE));
    }

    /**
     * Applies the plan. Blocking changes are written too; the caller decides (`--force`) beforehand.
     *
     * @param list<Target> $targets
     */
    public function apply(Plan $plan, array $targets, ProjectConfig $config): void
    {
        foreach ($plan->changes as $change) {
            match ($change->status) {
                Status::Create => $this->writeBlock($change, create: true),
                Status::Update, Status::Drifted => $this->writeBlock($change, create: false),
                Status::Remove, Status::RemoveDrifted => $this->removeBlock($change),
                Status::Current, Status::Forget => null,
            };
        }

        if ($plan->createClaudeFile) {
            $this->fs->dumpFile($this->root . '/' . self::CLAUDE_FILE, "@AGENTS.md\n");
        }

        $files = [];
        foreach ($targets as $target) {
            $files[$target->path] = new LockEntry($target->block, $target->hash(), $target->sources);
        }
        $lock = new Lock($this->packageVersion, $config->layers, $config->exclude, $files);
        $json = $lock->toJson();
        if (!is_file($this->root . '/' . Lock::FILE) || file_get_contents($this->root . '/' . Lock::FILE) !== $json) {
            $this->fs->dumpFile($this->root . '/' . Lock::FILE, $json);
        }
    }

    private function currentBlock(string $path, string $block): ?string
    {
        $file = $this->root . '/' . $path;
        if (!is_file($file)) {
            return null;
        }

        return ManagedBlock::find((string) file_get_contents($file), $block)['content'] ?? null;
    }

    private function writeBlock(Change $change, bool $create): void
    {
        $file = $this->root . '/' . $change->path;
        $content = (string) $change->desired;
        $isAgents = $change->kind === Target::KIND_AGENTS;

        if (!is_file($file)) {
            $block = ManagedBlock::render($change->block, $content) . "\n";
            $this->fs->dumpFile($file, $isAgents ? "# Agent instructions\n\n" . $block : $block);

            return;
        }

        $text = (string) file_get_contents($file);
        $replaced = ManagedBlock::replace($text, $change->block, $content);
        if ($replaced === null) {
            $block = ManagedBlock::render($change->block, $content);
            $replaced = trim($text) === ''
                ? $block . "\n"
                : ($isAgents ? rtrim($text) . "\n\n" . $block . "\n" : $block . "\n\n" . ltrim($text));
        }
        $this->fs->dumpFile($file, $replaced);
    }

    private function removeBlock(Change $change): void
    {
        $file = $this->root . '/' . $change->path;
        $text = (string) file_get_contents($file);
        $remaining = ManagedBlock::remove($text, $change->block);

        if (trim($remaining) === '' && $change->path !== Renderer::AGENTS_FILE) {
            $this->fs->remove($file);

            return;
        }
        $this->fs->dumpFile($file, $remaining);
    }
}
