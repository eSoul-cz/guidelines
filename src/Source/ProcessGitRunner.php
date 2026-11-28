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

    /**
     * Variables that pick the repository git works on. A child process inherits them, and they would override `$cwd`
     * (a git hook sets `GIT_DIR`, for one). Credentials such as `SSH_AUTH_SOCK` are not among them and stay.
     */
    private const array REPOSITORY_VARIABLES = [
        'GIT_DIR',
        'GIT_WORK_TREE',
        'GIT_INDEX_FILE',
        'GIT_OBJECT_DIRECTORY',
        'GIT_ALTERNATE_OBJECT_DIRECTORIES',
        'GIT_COMMON_DIR',
        'GIT_NAMESPACE',
        'GIT_PREFIX',
        'GIT_IMPLICIT_WORK_TREE',
        'GIT_GRAFT_FILE',
        'GIT_SHALLOW_FILE',
        'GIT_REPLACE_REF_BASE',
        'GIT_QUARANTINE_PATH',
    ];

    public function run(array $arguments, ?string $cwd = null): string {
        if (new ExecutableFinder()->find('git') === null) {
            throw new GuidelinesException('`git` was not found on the PATH. `sync`, `update` and `source:add` need it to fetch a guideline source; `check` does not.');
        }

        $process = new Process(['git', ...$arguments], $cwd, array_fill_keys(self::REPOSITORY_VARIABLES, false), null, self::TIMEOUT_SECONDS);
        $process->run();
        if ( ! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : trim($process->getOutput());

            throw new GuidelinesException(sprintf('`git %s` failed (exit %d): %s', implode(' ', array_slice($arguments, 0, 2)), (int) $process->getExitCode(), $message));
        }

        return $process->getOutput();
    }
}
