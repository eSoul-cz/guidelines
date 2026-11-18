<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\Config\ProjectConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'list', description: 'List the available guideline layers and their files')]
final class ListCommand extends ProjectCommand
{
    protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int
    {
        $selected = ProjectConfig::exists($root) ? ProjectConfig::load($root)->layers : [];

        $rows = [];
        foreach ($this->layers->layers() as $layer) {
            $rows[] = [
                in_array($layer, $selected, true) ? '*' : '',
                $layer,
                implode(', ', array_map(static fn ($f): string => $f->name . '.md', $this->layers->files($layer))),
            ];
        }
        $io->table(['Used', 'Layer', 'Files'], $rows);
        if ($selected !== []) {
            $io->writeln('`*` = selected in ' . ProjectConfig::FILE);
        }
        $io->writeln('Commands: init, sync, check, list (see `--help`).');

        return self::SUCCESS;
    }
}
