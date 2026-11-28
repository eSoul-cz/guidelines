<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use Esoul\Guidelines\Config\LockSource;
use Esoul\Guidelines\Layer\LayerCatalog;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What `sync` or `update` will do to the committed source copies, worked out without writing anything: the fetched
 * checkouts (the layers can be rendered from them before the copy is written), the new lock records and the files to
 * remove. {@see apply()} writes the copies; {@see cleanup()} removes the temporary checkouts either way.
 */
final readonly class SourcePlan
{
    /**
     * @param array<string, string> $dirs source name => directory to render its layers from (fetched sources only)
     * @param array<string, LockSource> $lockSources the lock records after the sync
     * @param list<string> $lines what to tell the user, one line each
     * @param list<string> $edited copied files edited or added by hand, which the sync would overwrite
     * @param array<string, list<string>> $changelog source name => lines its CHANGELOG.md gained (update only)
     * @param array<string, Checkout> $checkouts source name => the checkout to copy from
     * @param array<string, list<string>> $prune source name => copied files of deselected layers to delete
     * @param list<string> $removed sources that are no longer configured
     */
    public function __construct(
        private string $root,
        public array $dirs,
        public array $lockSources,
        public array $lines,
        public array $edited,
        public array $changelog,
        private array $checkouts,
        private array $prune,
        private array $removed,
    ) {
    }

    /** Whether applying the plan writes to a source copy. */
    public function changes(): bool {
        return $this->checkouts !== [] || $this->prune !== [] || $this->removed !== [];
    }

    public function apply(): void {
        $fs = new Filesystem();

        foreach ($this->checkouts as $name => $checkout) {
            $dir = LayerCatalog::copyDir($this->root, $name);
            $fs->remove($dir);
            foreach (array_keys($this->lockSources[$name]->files) as $relative) {
                $fs->copy($checkout->guidelinesDir() . '/' . $relative, $dir . '/' . $relative, true);
            }
        }
        foreach ($this->prune as $name => $files) {
            foreach ($files as $relative) {
                $fs->remove(LayerCatalog::copyDir($this->root, $name) . '/' . $relative);
            }
        }
        foreach ($this->removed as $name) {
            $fs->remove(LayerCatalog::copyDir($this->root, $name));
        }

        if ($this->prune !== [] || $this->removed !== []) {
            $this->removeEmptyDirs($this->root . '/' . LayerCatalog::COPY_DIR);
            @rmdir($this->root . '/.esoul'); // only when nothing else lives there
        }
    }

    public function cleanup(): void {
        foreach ($this->checkouts as $checkout) {
            $checkout->cleanup();
        }
    }

    private function removeEmptyDirs(string $dir): void {
        if ( ! is_dir($dir)) {
            return;
        }
        $scanned = scandir($dir);
        $entries = $scanned === false ? [] : $scanned;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($dir . '/' . $entry)) {
                $this->removeEmptyDirs($dir . '/' . $entry);
            }
        }
        @rmdir($dir); // fails, as wanted, while the directory holds something
    }
}
