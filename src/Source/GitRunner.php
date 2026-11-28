<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use Esoul\Guidelines\GuidelinesException;

/** Runs one `git` command. The only process the installer starts, injected so tests can observe or replace it. */
interface GitRunner
{
    /**
     * @param list<string> $arguments the arguments after `git`
     * @param string|null $cwd working directory; null for the current one
     * @return string standard output
     * @throws GuidelinesException with git's own message when the command fails
     */
    public function run(array $arguments, ?string $cwd = null): string;
}
