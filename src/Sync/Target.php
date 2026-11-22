<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

/** A managed block that should exist in a project file after a sync. */
final readonly class Target
{
    public const string KIND_GUIDELINE = 'guideline';
    public const string KIND_INDEX = 'index';
    public const string KIND_AGENTS = 'agents';

    /**
     * @param array<string, string> $sources source file => sha256
     */
    public function __construct(
        public string $kind,
        /** Path relative to the project root. */
        public string $path,
        public string $block,
        /** Block content (without markers). */
        public string $content,
        public array $sources = [],
    ) {
    }

    public function hash(): string {
        return ManagedBlock::hash($this->content);
    }
}
