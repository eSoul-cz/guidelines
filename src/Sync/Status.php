<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

enum Status: string
{
    /** File or block does not exist yet. */
    case Create = 'create';
    /** Block is unmodified since the last sync and the fresh render differs. */
    case Update = 'update';
    case Current = 'current';
    /** Block was edited locally (or is not tracked in the lock) and differs from the fresh render. */
    case Drifted = 'drifted';
    /** No longer produced; block is unmodified and will be removed. */
    case Remove = 'remove';
    /** No longer produced, but the block holds local edits. */
    case RemoveDrifted = 'remove-drifted';
    /** Lock entry whose block is already gone and no longer produced. */
    case Forget = 'forget';

    public function isBlocking(): bool
    {
        return $this === self::Drifted || $this === self::RemoveDrifted;
    }
}
