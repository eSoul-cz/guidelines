<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use Esoul\Guidelines\GuidelinesException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** The `git` CLI, so a fetch uses the developer's own SSH keys and credentials and the installer stores none. */
final class ProcessGitRunner implements GitRunner
{
    private const int TIMEOUT_SECONDS = 600;

    public function run(array $arguments, ?string $cwd = null): string {
        if (new ExecutableFinder()->find('git') === null) {
            throw new GuidelinesException('`git` was not found on the PATH. `sync`, `update` and `source:add` need it to fetch a guideline source; `check` does not.');
        }

        $process = new Process(['git', ...$arguments], $cwd, null, null, self::TIMEOUT_SECONDS);
        $process->run();
        if ( ! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : trim($process->getOutput());

            throw new GuidelinesException(sprintf('`git %s` failed (exit %d): %s', implode(' ', array_slice($arguments, 0, 2)), (int) $process->getExitCode(), $message));
        }

        return $process->getOutput();
    }
}
