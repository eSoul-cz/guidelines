<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Sync\Change;
use Esoul\Guidelines\Sync\LineDiff;
use Esoul\Guidelines\Sync\Renderer;
use Esoul\Guidelines\Sync\Status;
use Esoul\Guidelines\Sync\Synchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'sync', description: 'Render the selected layers into docs/project_guidelines, AGENTS.md and the lock file')]
final class SyncCommand extends ProjectCommand
{
    public function __construct(LayerRepository $layers, private readonly string $packageVersion) {
        parent::__construct($layers);
    }

    protected function configure(): void {
        parent::configure();
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite managed blocks that were edited locally')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would change without writing anything');
    }

    protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int {
        $config = ProjectConfig::load($root);
        $targets = new Renderer($this->layers)->render($config);
        $sync = new Synchronizer($root, $this->packageVersion);
        $plan = $sync->plan($targets, Lock::load($root));

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $verbose = $io->isVerbose();

        if ($dryRun) {
            $io->title('Dry run');
        }
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

        if ( ! $plan->hasChanges()) {
            $io->success('Guidelines are up to date.');
            if ( ! $dryRun) {
                $sync->apply($plan, $targets, $config); // refresh the lock (e.g. package version)
            }

            return self::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln('Dry run: nothing was written.' . ($blocked !== [] ? ' `--force` would overwrite the blocks marked "drifted".' : ''));

            return self::SUCCESS;
        }

        $sync->apply($plan, $targets, $config);
        $io->success('Guidelines synced.');

        return self::SUCCESS;
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
