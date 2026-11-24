<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

final readonly class CheckResult
{
    /**
     * @param list<string> $drift
     * @param list<string> $outdated
     * @param list<string> $exceptions problems with the recorded project exceptions
     */
    public function __construct(
        public array $drift,
        public array $outdated,
        public array $exceptions = [],
    ) {
    }

    /** @param list<string> $problems */
    public function withExceptions(array $problems): self {
        return new self($this->drift, $this->outdated, $problems);
    }

    public function ok(): bool {
        return $this->drift === [] && $this->outdated === [] && $this->exceptions === [];
    }
}
