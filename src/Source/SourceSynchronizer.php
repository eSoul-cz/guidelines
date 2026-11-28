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
                $selected = LayerId::selected($config->layers, $name);
                $locked = $lock?->sources[$name] ?? null;
                $configChanged = $locked !== null && ($locked->url !== $spec->url || $locked->ref !== $spec->ref);
                if ($configChanged) {
                    $lines[] = sprintf('  <comment>source</comment>  %s: url or ref changed since the lock; fetching the head of "%s"', $name, $spec->ref);
                    $locked = null;
                }
                $updating = in_array($name, $update, true);
                $state = $locked === null ? null : $this->inspect($name, $locked, $selected);

                if ($state !== null && $state['handEdits'] !== [] && ! $force) {
                    foreach ($state['handEdits'] as $message) {
                        $edited[] = $message;
                    }
                    $lockSources[$name] = $locked;

                    continue;
                }

                if ($locked !== null && $state !== null && ! $updating && ! $state['incomplete'] && $state['handEdits'] === []) {
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
                    // Nothing new on the ref: keep the copy as it is.
                    $checkout->cleanup();
                    unset($checkouts[$name]);
                    $lockSources[$name] = $locked;
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
        foreach (array_keys($lock->sources ?? []) as $name) {
            if ( ! isset($config->sources[$name])) {
                $removed[] = $name;
                $lines[] = sprintf('  <comment>remove</comment>  %s: no longer configured', $name);
            }
        }

        return new SourcePlan($this->root, $dirs, $lockSources, $lines, $edited, $changelog, $checkouts, $prune, $removed);
    }

    /**
     * @param list<string> $selected
     * @return array{incomplete: bool, handEdits: list<string>, desired: array<string, string>, prune: list<string>}
     */
    private function inspect(string $name, LockSource $locked, array $selected): array {
        $dir = LayerCatalog::copyDir($this->root, $name);

        $desired = [];
        $prune = [];
        foreach ($locked->files as $relative => $sha) {
            $layer = CopyTree::layerOf($relative);
            if ($layer === null || in_array($layer, $selected, true)) {
                $desired[$relative] = $sha;
            } elseif (is_file($dir . '/' . $relative)) {
                $prune[] = $relative;
            }
        }

        ksort($desired);
        $incomplete = ! isset($desired[SourceManifest::FILE]);
        foreach ($selected as $layer) {
            $incomplete = $incomplete || array_all(array_keys($desired), static fn (string $relative): bool => CopyTree::layerOf($relative) !== $layer);
        }

        $handEdits = [];
        foreach ($desired as $relative => $sha) {
            $actual = CopyTree::hash($dir, $relative);
            if ($actual === null) {
                $incomplete = true;
            } elseif ($actual !== $sha) {
                $handEdits[] = sprintf('%s/%s/%s was edited by hand (differs from the lock)', LayerCatalog::COPY_DIR, $name, $relative);
            }
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
