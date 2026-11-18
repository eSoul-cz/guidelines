<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

/** Planned action for one managed block. */
final readonly class Change
{
    public function __construct(
        public Status $status,
        public string $path,
        public string $block,
        public string $kind,
        /** Current block content, if the block exists. */
        public ?string $current,
        /** Desired block content; null when the block is going away. */
        public ?string $desired,
        public string $note = '',
    ) {
    }

    /** @return array{added: int, removed: int} */
    public function lineStats(): array
    {
        $ops = LineDiff::diff($this->current ?? '', $this->desired ?? '');
        $added = $removed = 0;
        foreach ($ops as [$op]) {
            $added += $op === '+' ? 1 : 0;
            $removed += $op === '-' ? 1 : 0;
        }

        return ['added' => $added, 'removed' => $removed];
    }
}
