<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Layer;

/** One guideline source file of a layer. */
final readonly class SourceFile
{
    /**
     * @param array<string, mixed> $frontmatter
     */
    public function __construct(
        public string $layer,
        /** Output name without `.md`, e.g. `testing`. */
        public string $name,
        /** Path relative to the guidelines resources dir, e.g. `general/testing.md`. */
        public string $relativePath,
        public array $frontmatter,
        public string $body,
        public string $hash,
    ) {
    }
}
