<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Shared base: `--working-dir` and error handling for user-facing failures. */
abstract class ProjectCommand extends Command
{
    public function __construct(protected readonly LayerRepository $layers) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->addOption('working-dir', 'd', InputOption::VALUE_REQUIRED, 'Project root (default: current directory)');
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $root = $input->getOption('working-dir') ?? getcwd();
        if ( ! is_string($root) || ! is_dir($root)) {
            $io->error('Project root does not exist.');

            return self::FAILURE;
        }

        try {
            $resolved = realpath($root);

            return $this->handle(rtrim($resolved === false ? $root : $resolved, '/'), $input, $io);
        } catch (GuidelinesException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }
    }

    abstract protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int;
}
