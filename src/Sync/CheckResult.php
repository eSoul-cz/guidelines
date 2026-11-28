<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

final readonly class CheckResult
{
    /**
     * @param list<string> $drift
     * @param list<string> $outdated
     * @param list<string> $exceptions problems with the recorded project exceptions
     * @param list<string> $sources problems with the committed copies of the guideline sources
     */
    public function __construct(
        public array $drift,
        public array $outdated,
        public array $exceptions = [],
        public array $sources = [],
    ) {
    }

    /** @param list<string> $problems */
    public function withExceptions(array $problems): self {
        return new self($this->drift, $this->outdated, $problems, $this->sources);
    }

    /** @param list<string> $problems */
    public function withSources(array $problems): self {
        return new self($this->drift, $this->outdated, $this->exceptions, $problems);
    }

    public function ok(): bool {
        return $this->drift === [] && $this->outdated === [] && $this->exceptions === [] && $this->sources === [];
    }
}
