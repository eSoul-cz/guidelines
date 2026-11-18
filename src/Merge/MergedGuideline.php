<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Merge;

/** One output guideline file produced by merging same-named source files of the selected layers. */
final readonly class MergedGuideline
{
    /**
     * @param list<string> $layers contributing layers in merge order
     * @param array<string, string> $sources source file (relative path) => sha256
     */
    public function __construct(
        public string $name,
        public string $title,
        public int $order,
        public string $summary,
        public bool $agents,
        public array $layers,
        public array $sources,
        /** Merged Markdown (H1 included), without the managed block markers. */
        public string $markdown,
    ) {
    }
}
