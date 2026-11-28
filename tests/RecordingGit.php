<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Source\GitRunner;

/** Runs another git runner and counts the calls: a command that must stay offline makes none. */
final class RecordingGit implements GitRunner
{
    public int $calls = 0;

    public function __construct(private readonly GitRunner $inner) {
    }

    public function run(array $arguments, ?string $cwd = null): string {
        ++$this->calls;

        return $this->inner->run($arguments, $cwd);
    }
}
