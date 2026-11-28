<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'sync', description: 'Render the selected layers into docs/project_guidelines, AGENTS.md and the lock file (fetches a guideline source only when its copy lacks a selected layer)')]
final class SyncCommand extends SyncingCommand
{
    protected function configure(): void {
        parent::configure();
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite managed blocks and source copies that were edited locally')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would change without writing anything');
    }

    protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int {
        return $this->synchronize($root, $io, (bool) $input->getOption('dry-run'), (bool) $input->getOption('force'));
    }
}
