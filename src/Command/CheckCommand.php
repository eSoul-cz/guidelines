<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\Layer\LayerCatalog;
use Esoul\Guidelines\Layer\LayerId;
use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Source\SourceAudit;
use Esoul\Guidelines\Sync\Checker;
use Esoul\Guidelines\Sync\ExceptionAudit;
use Esoul\Guidelines\Sync\Renderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'check', description: 'CI check, offline: fail on drift (local edits in managed blocks), outdated guidelines, an edited source copy or invalid project exceptions')]
final class CheckCommand extends ProjectCommand
{
    public function __construct(LayerRepository $layers, private readonly string $packageVersion) {
        parent::__construct($layers);
    }

    protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int {
        $config = ProjectConfig::load($root);
        $lock = Lock::load($root);
        $catalog = new LayerCatalog($this->layers, $root);

        // Offline: the sources are read from their committed copies, never fetched.
        $sourceProblems = new SourceAudit($root, $this->packageVersion)->problems($config, $lock);
        $renderable = array_all($config->layers, static fn (string $layer): bool => LayerId::source($layer) === null || $catalog->has($layer));
        // A layer a copy lacks cannot be rendered (the audit reports it); the lock and the drift are still checked.
        $result = new Checker($root)->check($config, $renderable ? new Renderer($catalog)->render($config) : null, $lock)
            ->withExceptions(new ExceptionAudit($catalog, $root)->problems($config))
            ->withSources($sourceProblems);

        if ($result->ok()) {
            $io->success('Guidelines are in sync with ' . Lock::FILE . ' and the selected layers.');

            return self::SUCCESS;
        }

        if ($result->sources !== []) {
            $io->error(array_merge(['Sources: the committed copies of the guideline sources in ' . LayerCatalog::COPY_DIR . '/ do not match ' . ProjectConfig::FILE . ' and ' . Lock::FILE . '.'], array_map(static fn (string $m): string => ' - ' . $m, $result->sources)));
        }
        if ($result->drift !== []) {
            $io->error(array_merge(['Drift: managed blocks differ from the lock.'], array_map(static fn (string $m): string => ' - ' . $m, $result->drift)));
        }
        if ($result->outdated !== []) {
            $io->error(array_merge(['Outdated: guidelines differ from a fresh render.'], array_map(static fn (string $m): string => ' - ' . $m, $result->outdated)));
        }
        if ($result->exceptions !== []) {
            $io->error(array_merge(['Exceptions: the project exceptions in ' . ProjectConfig::FILE . ' are not valid.'], array_map(static fn (string $m): string => ' - ' . $m, $result->exceptions)));
        }

        return self::FAILURE;
    }
}
