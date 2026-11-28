<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\Layer\LayerCatalog;
use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Source\SourceFetcher;
use Esoul\Guidelines\Source\SourceSynchronizer;
use Esoul\Guidelines\Sync\Change;
use Esoul\Guidelines\Sync\ExceptionAudit;
use Esoul\Guidelines\Sync\LineDiff;
use Esoul\Guidelines\Sync\Renderer;
use Esoul\Guidelines\Sync\Status;
use Esoul\Guidelines\Sync\Synchronizer;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Base of the commands that render the project's guidelines: `sync`, `update` and `source:add`. */
abstract class SyncingCommand extends ProjectCommand
{
    public function __construct(
        LayerRepository $layers,
        protected readonly SourceFetcher $fetcher,
        protected readonly string $packageVersion,
    ) {
        parent::__construct($layers);
    }

    /**
     * @param list<string> $update names of the sources to move to the head of their ref
     */
    final protected function synchronize(string $root, SymfonyStyle $io, bool $dryRun, bool $force, array $update = []): int {
        $config = ProjectConfig::load($root);
        $lock = Lock::load($root);

        $sources = new SourceSynchronizer($root, $this->fetcher, $this->packageVersion)->prepare($config, $lock, $force, $update);

        try {
            if ($dryRun) {
                $io->title('Dry run');
            }
            foreach ($sources->lines as $line) {
                $io->writeln($line);
            }
            if ($sources->edited !== [] && ! $force) {
                $io->error(array_merge(
                    ['Refusing to overwrite the copy of a guideline source that was edited by hand:'],
                    array_map(static fn (string $m): string => ' - ' . $m, $sources->edited),
                    ['', 'The copy is generated from the source. Revert your edit, or run `esoul-guidelines sync --force` to discard it.'],
                ));

                return self::FAILURE;
            }

            $catalog = new LayerCatalog($this->layers, $root, $sources->dirs);
            $targets = new Renderer($catalog)->render($config);
            $exceptionProblems = new ExceptionAudit($catalog, $root)->problems($config);
            if ($exceptionProblems !== []) {
                $io->warning(array_merge(
                    ['Problems with the project exceptions (a missing or refused rule gets no marker); `esoul-guidelines check` fails until they are fixed:'],
                    array_map(static fn (string $m): string => ' - ' . $m, $exceptionProblems),
                ));
            }
            $sync = new Synchronizer($root, $this->packageVersion);
            $plan = $sync->plan($targets, $lock);

            $verbose = $io->isVerbose();
            foreach ($plan->changes as $change) {
                if ($change->status === Status::Current && ! $verbose) {
                    continue;
                }
                $io->writeln($this->describe($change));
                if ($dryRun && $io->isVeryVerbose() && $change->status !== Status::Current) {
                    foreach (LineDiff::diff($change->current ?? '', $change->desired ?? '') as [$op, $line]) {
                        if ($op !== ' ') {
                            $io->writeln('    ' . $op . ' ' . $line);
                        }
                    }
                }
            }
            if ($plan->createClaudeFile) {
                $io->writeln('  <info>create</info>  CLAUDE.md (@AGENTS.md)');
            }

            $blocked = $plan->blocked();
            if ($blocked !== [] && ! $force) {
                $io->error(array_merge(
                    ['Refusing to overwrite managed blocks with local edits:'],
                    array_map(static fn (Change $c): string => sprintf(' - %s [%s]: %s', $c->path, $c->block, $c->note), $blocked),
                    ['', 'Move your changes below the block, or run `esoul-guidelines sync --force` to discard them.'],
                ));

                return self::FAILURE;
            }

            if ( ! $plan->hasChanges() && ! $sources->changes()) {
                $io->success('Guidelines are up to date.');
                if ( ! $dryRun) {
                    $sync->apply($plan, $targets, $config, $sources->lockSources); // refresh the lock (e.g. package version)
                }
                $this->printChangelog($io, $sources->changelog);

                return self::SUCCESS;
            }

            if ($dryRun) {
                $io->writeln('Dry run: nothing was written.' . ($blocked !== [] ? ' `--force` would overwrite the blocks marked "drifted".' : ''));
                $this->printChangelog($io, $sources->changelog);

                return self::SUCCESS;
            }

            $sources->apply();
            $sync->apply($plan, $targets, $config, $sources->lockSources);
            $io->success('Guidelines synced.');
            $this->printChangelog($io, $sources->changelog);

            return self::SUCCESS;
        } finally {
            $sources->cleanup();
        }
    }

    /**
     * @param array<string, list<string>> $changelog source name => lines its CHANGELOG.md gained
     */
    private function printChangelog(SymfonyStyle $io, array $changelog): void {
        foreach ($changelog as $name => $lines) {
            $io->section(sprintf('Changelog of %s', $name));
            $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
            if ($lines === []) {
                $io->writeln('The source added no lines to its CHANGELOG.md.');
            }
            foreach ($lines as $line) {
                $io->writeln('  ' . OutputFormatter::escape($line));
            }
        }
    }

    private function describe(Change $change): string {
        $stats = $change->lineStats();
        $detail = match ($change->status) {
            Status::Create => sprintf('+%d', $stats['added']),
            Status::Update, Status::Drifted => sprintf('+%d -%d', $stats['added'], $stats['removed']),
            Status::Remove, Status::RemoveDrifted => sprintf('-%d', $stats['removed']),
            default => '',
        };
        $label = match ($change->status) {
            Status::Create => '<info>create</info>',
            Status::Update => '<comment>update</comment>',
            Status::Current => 'current',
            Status::Drifted => '<error>drifted</error>',
            Status::Remove => '<comment>remove</comment>',
            Status::RemoveDrifted => '<error>drifted</error>',
            Status::Forget => '<comment>forget</comment>',
        };

        return trim(sprintf('  %s  %s %s%s', $label, $change->path, $detail !== '' ? '(' . $detail . ')' : '', $change->note !== '' ? ' - ' . $change->note : ''));
    }
}
