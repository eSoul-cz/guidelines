<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Config;

/** Lock record of one managed block. */
final readonly class LockEntry
{
    /**
     * @param array<string, string> $sources source file => sha256 (empty for derived blocks like the index)
     */
    public function __construct(
        public string $block,
        public string $sha256,
        public array $sources = [],
    ) {
    }
}
