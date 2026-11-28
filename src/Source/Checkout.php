<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use Esoul\Guidelines\GuidelinesException;
use Symfony\Component\Filesystem\Filesystem;

/** A temporary checkout of a guideline source at one commit. Remove it with {@see cleanup()}. */
final class Checkout
{
    public function __construct(
        private readonly GitRunner $git,
        private readonly string $dir,
        /** Full sha of the checked-out commit. */
        public readonly string $commit,
    ) {
    }

    /** The `guidelines/` directory of the source: layers and `source.json`. */
    public function guidelinesDir(): string {
        return $this->dir . '/guidelines';
    }

    /**
     * The lines `CHANGELOG.md` gained between an older commit and this one, without the diff markers.
     *
     * @return list<string>
     * @throws GuidelinesException when git cannot diff the two commits (the old commit is gone)
     */
    public function changelogSince(string $oldCommit): array {
        $diff = $this->git->run(['diff', '--no-color', '--unified=0', $oldCommit, $this->commit, '--', 'CHANGELOG.md'], $this->dir);

        $added = [];
        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $added[] = substr($line, 1);
            }
        }

        return $added;
    }

    public function cleanup(): void {
        new Filesystem()->remove($this->dir);
    }
}
