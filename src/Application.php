<?php

declare(strict_types=1);

namespace Esoul\Guidelines;

use Esoul\Guidelines\Command\CheckCommand;
use Esoul\Guidelines\Command\InitCommand;
use Esoul\Guidelines\Command\ListCommand;
use Esoul\Guidelines\Command\SyncCommand;
use Esoul\Guidelines\Layer\LayerRepository;
use Symfony\Component\Console\Application as ConsoleApplication;

final class Application extends ConsoleApplication
{
    public function __construct(?LayerRepository $layers = null, ?string $version = null) {
        $version ??= PackageVersion::detect();
        $layers ??= LayerRepository::bundled();

        parent::__construct('esoul-guidelines', $version);

        $this->addCommands([
            new InitCommand($layers),
            new SyncCommand($layers, $version),
            new CheckCommand($layers),
            new ListCommand($layers),
        ]);
        $this->setCatchExceptions(true);
    }
}
