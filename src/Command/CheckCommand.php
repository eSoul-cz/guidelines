<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\Sync\Checker;
use Esoul\Guidelines\Sync\ExceptionAudit;
use Esoul\Guidelines\Sync\Renderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'check', description: 'CI check: fail on drift (local edits in managed blocks), outdated guidelines or invalid project exceptions')]
final class CheckCommand extends ProjectCommand
{
    protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int {
        $config = ProjectConfig::load($root);
        $targets = new Renderer($this->layers)->render($config);
        $result = new Checker($root)->check($config, $targets, Lock::load($root))
            ->withExceptions(new ExceptionAudit($this->layers, $root)->problems($config));

        if ($result->ok()) {
            $io->success('Guidelines are in sync with ' . Lock::FILE . ' and the selected layers.');

            return self::SUCCESS;
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
