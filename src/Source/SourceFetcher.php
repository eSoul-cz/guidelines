<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use Esoul\Guidelines\GuidelinesException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/** Fetches a guideline source with git into a temporary directory. */
final readonly class SourceFetcher
{
    public function __construct(private GitRunner $git) {
    }

    /**
     * @param string|null $commit a commit to check out (the locked one); null checks out the head of `$ref`
     */
    public function fetch(string $url, string $ref, ?string $commit = null): Checkout {
        $dir = sys_get_temp_dir() . '/esoul-guidelines-source-' . bin2hex(random_bytes(6));

        try {
            $this->git->run(['clone', '--quiet', '--no-checkout', '--', $url, $dir]);
            $sha = $commit !== null ? $this->resolve($dir, $commit, $url, sprintf('commit %s', $commit)) : $this->resolveRef($dir, $ref, $url);
            $this->git->run(['checkout', '--quiet', '--detach', $sha], $dir);
            $this->assertNoSymlinks($dir, $url, $sha);
        } catch (Throwable $e) {
            // Whatever failed (a git error, a timeout), the caller gets no Checkout to clean up: remove the clone here.
            new Filesystem()->remove($dir);

            throw $e;
        }

        return new Checkout($this->git, $dir, $sha);
    }

    /** A symlink in `guidelines/` could pull a local file (a private key) into the copy that is committed: refuse the source. */
    private function assertNoSymlinks(string $dir, string $url, string $sha): void {
        $guidelines = $dir . '/guidelines';
        $links = is_link($guidelines) ? ['guidelines'] : array_map(static fn (string $link): string => 'guidelines/' . $link, CopyTree::symlinks($guidelines));
        if ($links !== []) {
            throw new GuidelinesException(sprintf('The source %s at %s contains symbolic links (%s). A guideline source may hold only regular files, so a link cannot pull a file of this machine into the committed copy.', $url, substr($sha, 0, 7), implode(', ', array_slice($links, 0, 5))));
        }
    }

    private function resolveRef(string $dir, string $ref, string $url): string {
        foreach (['refs/remotes/origin/' . $ref, 'refs/tags/' . $ref] as $full) {
            $sha = $this->revParse($dir, $full);
            if ($sha !== null) {
                return $sha;
            }
        }

        throw new GuidelinesException(sprintf('The source %s has no branch or tag "%s" (ref).', $url, $ref));
    }

    private function resolve(string $dir, string $revision, string $url, string $what): string {
        $sha = preg_match('/^[0-9a-f]{7,64}$/', $revision) === 1 ? $this->revParse($dir, $revision) : null;
        if ($sha === null) {
            throw new GuidelinesException(sprintf('The source %s has no %s any more. Run `esoul-guidelines update` to move the pin to the head of the ref.', $url, $what));
        }

        return $sha;
    }

    private function revParse(string $dir, string $revision): ?string {
        try {
            $sha = trim($this->git->run(['rev-parse', '--verify', '--quiet', $revision . '^{commit}'], $dir));
        } catch (GuidelinesException) {
            return null;
        }

        return $sha === '' ? null : $sha;
    }
}
