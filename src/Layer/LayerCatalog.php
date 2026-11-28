<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Layer;

use Esoul\Guidelines\GuidelinesException;

/**
 * Every layer a project can select: the bundled layers and the layers of its guideline sources, read from the
 * committed copy `.esoul/guidelines/<source>/` (or from the directory given for a source, e.g. a fresh checkout).
 */
final class LayerCatalog
{
    /** Where a project keeps the copy of its guideline sources, relative to the project root. */
    public const string COPY_DIR = '.esoul/guidelines';

    /** @var array<string, LayerRepository> */
    private array $sources = [];

    /**
     * @param array<int|string, string> $sourceDirs source name => directory with the layers, instead of the committed copy
     */
    public function __construct(
        private readonly LayerRepository $bundled,
        private readonly string $root,
        private readonly array $sourceDirs = [],
    ) {
    }

    public static function copyDir(string $root, string $source): string {
        return $root . '/' . self::COPY_DIR . '/' . $source;
    }

    /** @return list<string> the bundled layers */
    public function layers(): array {
        return $this->bundled->layers();
    }

    /** @return list<string> the prefixed layers of one source; empty when the project has no copy of it */
    public function sourceLayers(string $source): array {
        return $this->repository($source)?->layers() ?? [];
    }

    public function hasCopy(string $source): bool {
        return is_dir($this->dir($source));
    }

    public function has(string $id): bool {
        $source = LayerId::source($id);

        return $source === null ? $this->bundled->has($id) : ($this->repository($source)?->has($id) ?? false);
    }

    /**
     * @return list<SourceFile>
     */
    public function files(string $id): array {
        $source = LayerId::source($id);
        if ($source === null) {
            return $this->bundled->files($id);
        }

        $repository = $this->repository($source);
        if ($repository === null) {
            throw new GuidelinesException(sprintf('Cannot read layer "%s": the project has no copy of the source "%s" in %s/%s/. Run `esoul-guidelines sync`.', $id, $source, self::COPY_DIR, $source));
        }

        return $repository->files($id);
    }

    private function dir(string $source): string {
        return $this->sourceDirs[$source] ?? self::copyDir($this->root, $source);
    }

    private function repository(string $source): ?LayerRepository {
        if ( ! LayerId::isValidSource($source) || ! is_dir($this->dir($source))) {
            return null;
        }

        return $this->sources[$source] ??= new LayerRepository($this->dir($source), $source);
    }
}
