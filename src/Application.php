<?php

declare(strict_types=1);

namespace Esoul\Guidelines;

use Esoul\Guidelines\Command\CheckCommand;
use Esoul\Guidelines\Command\InitCommand;
use Esoul\Guidelines\Command\ListCommand;
use Esoul\Guidelines\Command\SourceAddCommand;
use Esoul\Guidelines\Command\SyncCommand;
use Esoul\Guidelines\Command\UpdateCommand;
use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Source\GitRunner;
use Esoul\Guidelines\Source\ProcessGitRunner;
use Esoul\Guidelines\Source\SourceFetcher;
use Symfony\Component\Console\Application as ConsoleApplication;

final class Application extends ConsoleApplication
{
    public function __construct(?LayerRepository $layers = null, ?string $version = null, ?GitRunner $git = null) {
        $version ??= PackageVersion::detect();
        $layers ??= LayerRepository::bundled();
        $fetcher = new SourceFetcher($git ?? new ProcessGitRunner());

        parent::__construct('esoul-guidelines', $version);

        $this->addCommands([
            new InitCommand($layers),
            new SyncCommand($layers, $fetcher, $version),
            new UpdateCommand($layers, $fetcher, $version),
            new SourceAddCommand($layers, $fetcher, $version),
            new CheckCommand($layers, $version),
            new ListCommand($layers),
        ]);
        $this->setCatchExceptions(true);
    }
}
