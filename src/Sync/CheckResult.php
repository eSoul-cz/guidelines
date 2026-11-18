<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

final readonly class CheckResult
{
    /**
     * @param list<string> $drift
     * @param list<string> $outdated
     */
    public function __construct(
        public array $drift,
        public array $outdated,
    ) {
    }

    public function ok(): bool
    {
        return $this->drift === [] && $this->outdated === [];
    }
}
