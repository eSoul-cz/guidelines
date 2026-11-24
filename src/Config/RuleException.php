<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Config;

use Esoul\Guidelines\GuidelinesException;

/**
 * A recorded project exception: one guideline rule the project deliberately does not follow, with what applies
 * instead and the accepted ADR that records the owner's approval. Listed in `esoul-guidelines.json` under `exceptions`.
 */
final readonly class RuleException
{
    /** Source files named like this, in any layer, hold the security and production-data rules, which cannot be excepted. */
    public const string PROTECTED_FILE = 'security-and-data';

    private const string RULE_PATTERN = '#^((?:general|[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*)/([a-z0-9][a-z0-9-]*)\.md)\#([^\s\#]+)$#u';

    public function __construct(
        /** `<layer>/<file>.md#<anchor>`, e.g. `package/lunar/architecture.md#money`. */
        public string $rule,
        /** Source file of the rule relative to the layers, e.g. `package/lunar/architecture.md`. */
        public string $file,
        /** Output file name without `.md`, e.g. `architecture`. */
        public string $name,
        /** Heading anchor in that file, e.g. `money`. */
        public string $anchor,
        public string $instead,
        /** ADR path relative to the project root. */
        public string $adr,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data, int $index): self {
        $where = sprintf('exceptions[%d] in %s', $index, ProjectConfig::FILE);
        foreach (['rule', 'instead', 'adr'] as $key) {
            if ( ! is_string($data[$key] ?? null) || trim($data[$key]) === '') {
                throw new GuidelinesException(sprintf('%s: "%s" must be a non-empty string.', $where, $key));
            }
        }
        $unknown = array_diff(array_map(strval(...), array_keys($data)), ['rule', 'instead', 'adr']);
        if ($unknown !== []) {
            throw new GuidelinesException(sprintf('%s: unknown key "%s" (allowed: rule, instead, adr).', $where, implode('", "', $unknown)));
        }

        /** @var array{rule: string, instead: string, adr: string} $data */
        $rule = trim($data['rule']);
        if (preg_match(self::RULE_PATTERN, $rule, $m) !== 1) {
            throw new GuidelinesException(sprintf('%s: rule "%s" must look like "<layer>/<file>.md#<heading-anchor>", for example "package/lunar/architecture.md#money".', $where, $rule));
        }

        $adr = trim($data['adr']);
        if (str_starts_with($adr, '/') || preg_match('#(^|/)\.\.(/|$)#', $adr) === 1) {
            throw new GuidelinesException(sprintf('%s: "adr" must be a path inside the project, relative to its root.', $where));
        }

        return new self($rule, $m[1], $m[2], $m[3], trim((string) preg_replace('/\s+/u', ' ', $data['instead'])), $adr);
    }

    /** @return array{rule: string, instead: string, adr: string} */
    public function toArray(): array {
        return ['rule' => $this->rule, 'instead' => $this->instead, 'adr' => $this->adr];
    }

    /** The security and production-data rules stay hard: no exception is accepted for them. */
    public function isProtected(): bool {
        return $this->name === self::PROTECTED_FILE;
    }

    /** The paragraph rendered under the overridden heading in the managed block. */
    public function marker(string $adrLinkPrefix): string {
        return sprintf('> **Project exception:** %s ([ADR](%s%s))', $this->instead, $adrLinkPrefix, $this->adr);
    }
}
