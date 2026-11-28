<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\GuidelinesException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update', description: 'Move guideline sources to the head of their ref, re-copy, re-render and print the lines their CHANGELOG.md gained')]
final class UpdateCommand extends SyncingCommand
{
    protected function configure(): void {
        parent::configure();
        $this
            ->addArgument('source', InputArgument::IS_ARRAY, 'Sources to update (default: every configured source)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite managed blocks and source copies that were edited locally')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would change without writing anything');
    }

    protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int {
        $config = ProjectConfig::load($root);
        $names = array_values(array_filter((array) $input->getArgument('source'), is_string(...)));
        if ($config->sources === []) {
            throw new GuidelinesException(sprintf('No guideline source is configured in %s. Add one with `esoul-guidelines source:add <url>`.', ProjectConfig::FILE));
        }

        return $this->synchronize($root, $io, (bool) $input->getOption('dry-run'), (bool) $input->getOption('force'), $names === [] ? array_map(strval(...), array_keys($config->sources)) : $names);
    }
}
