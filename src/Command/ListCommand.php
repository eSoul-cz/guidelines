<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\Layer\LayerCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'list', description: 'List the available guideline layers (bundled, and those of the guideline sources whose copy is present) and their files')]
final class ListCommand extends ProjectCommand
{
    protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int {
        $config = ProjectConfig::exists($root) ? ProjectConfig::load($root) : null;
        $selected = $config->layers ?? [];
        $catalog = new LayerCatalog($this->layers, $root);

        $layers = $catalog->layers();
        $missingCopies = [];
        foreach (array_keys($config->sources ?? []) as $source) {
            if ($catalog->hasCopy($source)) {
                $layers = [...$layers, ...$catalog->sourceLayers($source)];
            } else {
                $missingCopies[] = $source;
            }
        }

        $rows = [];
        foreach ($layers as $layer) {
            $rows[] = [
                in_array($layer, $selected, true) ? '*' : '',
                $layer,
                implode(', ', array_map(static fn ($f): string => $f->name . '.md', $catalog->files($layer))),
            ];
        }
        $io->table(['Used', 'Layer', 'Files'], $rows);
        if ($selected !== []) {
            $io->writeln('`*` = selected in ' . ProjectConfig::FILE);
        }
        if ($missingCopies !== []) {
            $io->writeln(sprintf('No copy of the source %s in %s/ yet: run `esoul-guidelines sync` to list its layers.', implode(', ', $missingCopies), LayerCatalog::COPY_DIR));
        }
        $io->writeln('Commands: init, sync, check, list, source:add, update (see `--help`).');

        return self::SUCCESS;
    }
}
