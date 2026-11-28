<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Config\LockSource;
use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerCatalog;
use Esoul\Guidelines\Layer\LayerId;
use Esoul\Guidelines\Layer\LayerRepository;
use Throwable;

/**
 * Works out the committed copies of the guideline sources (`.esoul/guidelines/<source>/`) for `sync` and `update`.
 *
 * A source is fetched only when the copy lacks something the config selects (or the lock has no record of it, or on
 * `update`); otherwise the copy is trusted as it is and nothing touches the network.
 */
final readonly class SourceSynchronizer
{
    public function __construct(
        private string $root,
        private SourceFetcher $fetcher,
        private string $installerVersion,
    ) {
    }

    /**
     * @param list<string> $update names of the sources to move to the head of their ref
     */
    public function prepare(ProjectConfig $config, ?Lock $lock, bool $force, array $update = []): SourcePlan {
        $update = array_map(strval(...), $update);
        foreach ($update as $name) {
            if ( ! isset($config->sources[$name])) {
                throw new GuidelinesException(sprintf('No source "%s" is configured in %s (configured: %s).', $name, ProjectConfig::FILE, $config->sources === [] ? 'none' : implode(', ', array_keys($config->sources))));
            }
        }

        $dirs = [];
        $lockSources = [];
        $lines = [];
        $edited = [];
        $changelog = [];
        $checkouts = [];
        $prune = [];

        try {
            foreach ($config->sources as $name => $spec) {
                $name = (string) $name; // PHP made an array key of a numeric name (`"123"`) an int
                $selected = LayerId::selected($config->layers, $name);
                $previous = $lock?->sources[$name] ?? null;
                $updating = in_array($name, $update, true);
                // The copy is audited against the lock it was written from, even when the config now asks for another url or ref.
                $state = $previous === null ? null : $this->inspect($name, $previous, $selected);

                if ($state !== null && $state['handEdits'] !== [] && ! $force) {
                    foreach ($state['handEdits'] as $message) {
                        $edited[] = $message;
                    }
                    $lockSources[$name] = $previous;

                    continue;
                }

                $locked = $previous;
                if ($previous !== null && ($previous->url !== $spec->url || $previous->ref !== $spec->ref)) {
                    $lines[] = sprintf('  <comment>source</comment>  %s: url or ref changed since the lock; fetching the head of "%s"', $name, $spec->ref);
                    $locked = null; // the lock says nothing about the new url or ref
                }

                if ($locked !== null && $state !== null && ! $updating && ! $state['incomplete'] && $state['handEdits'] === []) {
                    // The copy is whole and untouched: trust it, but a copy that needs a newer installer is still an error.
                    $this->assertCopyUsable($name, $spec->url);
                    $lockSources[$name] = new LockSource($locked->url, $locked->ref, $locked->commit, $state['desired']);
                    if ($state['prune'] !== []) {
                        $prune[$name] = $state['prune'];
                        $lines[] = sprintf('  <comment>prune</comment>   %s: %s', $name, implode(', ', $state['prune']));
                    }

                    continue;
                }

                $checkout = $this->fetcher->fetch($spec->url, $spec->ref, $updating || $locked === null ? null : $locked->commit);
                $checkouts[$name] = $checkout;
                $origin = sprintf('%s (%s)', $spec->url, $this->short($checkout->commit));
                $manifest = SourceManifest::read($checkout->guidelinesDir() . '/' . SourceManifest::FILE, $origin);
                $manifest->assertNamed($name, $origin);
                $manifest->assertCompatible($this->installerVersion);

                $files = $this->copiedFiles($name, $checkout, $selected, $spec->url);
                $new = new LockSource($spec->url, $spec->ref, $checkout->commit, $files);

                if ($locked !== null && $state !== null && $locked->commit === $checkout->commit && ! $state['incomplete'] && $state['handEdits'] === [] && $state['desired'] === $files) {
                    // Nothing new on the ref: keep the copy as it is (without the files of the layers that were deselected).
                    $checkout->cleanup();
                    unset($checkouts[$name]);
                    $lockSources[$name] = new LockSource($locked->url, $locked->ref, $locked->commit, $state['desired']);
                    $lines[] = sprintf('  <info>current</info>  %s is already at %s', $name, $this->short($locked->commit));
                    if ($state['prune'] !== []) {
                        $prune[$name] = $state['prune'];
                    }

                    continue;
                }

                $dirs[$name] = $checkout->guidelinesDir();
                $lockSources[$name] = $new;
                if ($locked !== null && $locked->commit !== $checkout->commit) {
                    $lines[] = sprintf('  <comment>update</comment>  %s %s -> %s (%s)', $name, $this->short($locked->commit), $this->short($checkout->commit), $spec->ref);
                    try {
                        $changelog[$name] = $checkout->changelogSince($locked->commit);
                    } catch (GuidelinesException $e) {
                        $lines[] = sprintf('  <comment>note</comment>    %s: the changelog lines are not available (%s)', $name, $e->getMessage());
                    }
                } else {
                    $lines[] = sprintf('  <info>fetch</info>   %s at %s (%s)', $name, $this->short($checkout->commit), $spec->ref);
                }
            }
        } catch (Throwable $e) {
            foreach ($checkouts as $checkout) {
                $checkout->cleanup();
            }

            throw $e;
        }

        $removed = [];
        foreach ($lock->sources ?? [] as $name => $locked) {
            $name = (string) $name;
            if (isset($config->sources[$name])) {
                continue;
            }
            // Removing a source deletes its whole copy: refuse while the copy holds edits of its own.
            $handEdits = $force ? [] : $this->inspect($name, $locked, [])['handEdits'];
            if ($handEdits !== []) {
                array_push($edited, ...$handEdits);

                continue;
            }
            $removed[] = $name;
            $lines[] = sprintf('  <comment>remove</comment>  %s: no longer configured', $name);
        }

        return new SourcePlan($this->root, $dirs, $lockSources, $lines, $edited, $changelog, $checkouts, $prune, $removed);
    }

    /**
     * The existing copy of a source, which the lock vouches for, needs no git: read its `source.json` and check what
     * a fetch would check, so that `sync` does not accept a copy that `check` rejects.
     */
    private function assertCopyUsable(string $name, string $url): void {
        $origin = sprintf('%s (%s/%s/)', $url, LayerCatalog::COPY_DIR, $name);
        $manifest = SourceManifest::read(LayerCatalog::copyDir($this->root, $name) . '/' . SourceManifest::FILE, $origin);
        $manifest->assertNamed($name, $origin);
        $manifest->assertCompatible($this->installerVersion);
    }

    /**
     * @param list<string> $selected
     * @return array{incomplete: bool, handEdits: list<string>, desired: array<string, string>, prune: list<string>}
     */
    private function inspect(string $name, LockSource $locked, array $selected): array {
        $dir = LayerCatalog::copyDir($this->root, $name);

        $desired = [];
        $prune = [];
        $handEdits = [];
        $incomplete = false;
        // Every locked file is checked, the ones of deselected layers too: pruning them would delete an edit.
        foreach ($locked->files as $relative => $sha) {
            $layer = CopyTree::layerOf($relative);
            $wanted = $layer === null || in_array($layer, $selected, true);
            if ($wanted) {
                $desired[$relative] = $sha;
            }

            $actual = CopyTree::hash($dir, $relative);
            if ($actual === null) {
                $incomplete = $incomplete || $wanted;
            } else {
                if ( ! $wanted) {
                    $prune[] = $relative;
                }
                if ($actual !== $sha) {
                    $handEdits[] = sprintf('%s/%s/%s was edited by hand (differs from the lock)', LayerCatalog::COPY_DIR, $name, $relative);
                }
            }
        }

        ksort($desired);
        $incomplete = $incomplete || ! isset($desired[SourceManifest::FILE]);
        foreach ($selected as $layer) {
            $incomplete = $incomplete || array_all(array_keys($desired), static fn (string $relative): bool => CopyTree::layerOf($relative) !== $layer);
        }

        foreach (CopyTree::files($dir) as $relative) {
            if ( ! isset($locked->files[$relative])) {
                $handEdits[] = sprintf('%s/%s/%s was added by hand (not in the lock)', LayerCatalog::COPY_DIR, $name, $relative);
            }
        }

        return ['incomplete' => $incomplete, 'handEdits' => $handEdits, 'desired' => $desired, 'prune' => $prune];
    }

    /**
     * The files to copy: `source.json` and every `.md` file of the selected layers, with their hashes.
     *
     * @param list<string> $selected
     * @return array<string, string>
     */
    private function copiedFiles(string $name, Checkout $checkout, array $selected, string $url): array {
        $dir = $checkout->guidelinesDir();
        $repository = new LayerRepository($dir, $name);

        $files = [SourceManifest::FILE => (string) CopyTree::hash($dir, SourceManifest::FILE)];
        foreach ($selected as $layer) {
            if ( ! $repository->has(LayerId::join($name, $layer))) {
                throw new GuidelinesException(sprintf('The source "%s" (%s) has no layer "%s" at %s. Available: %s.', $name, $url, $layer, $this->short($checkout->commit), $repository->layers() === [] ? 'none' : implode(', ', $repository->layers())));
            }
            foreach (CopyTree::layerFiles($dir, $layer) as $relative) {
                $files[$relative] = (string) CopyTree::hash($dir, $relative);
            }
        }

        ksort($files);

        return $files;
    }

    private function short(string $commit): string {
        return substr($commit, 0, 7);
    }
}
