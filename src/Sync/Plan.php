<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

final readonly class Plan
{
    /**
     * @param list<Change> $changes
     */
    public function __construct(
        public array $changes,
        public bool $createClaudeFile,
    ) {
    }

    /** @return list<Change> blocks with local edits that a sync would overwrite or remove */
    public function blocked(): array {
        return array_values(array_filter($this->changes, static fn (Change $c): bool => $c->status->isBlocking()));
    }

    public function hasChanges(): bool {
        return $this->createClaudeFile || array_any($this->changes, static fn (Change $c): bool => $c->status !== Status::Current);
    }
}
