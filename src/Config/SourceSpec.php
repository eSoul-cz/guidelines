<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Config;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerId;

/** A guideline source of the project: `"sources": {"<name>": {"url": "...", "ref": "main"}}`. */
final readonly class SourceSpec
{
    public const string DEFAULT_REF = 'main';

    public function __construct(
        public string $name,
        /** Anything `git clone` accepts: an SSH or HTTPS URL, or a path. */
        public string $url,
        /** A branch or a tag. */
        public string $ref = self::DEFAULT_REF,
    ) {
    }

    public static function fromArray(string $name, mixed $data): self {
        $where = sprintf('sources.%s in %s', $name, ProjectConfig::FILE);
        if ( ! LayerId::isValidSource($name)) {
            throw new GuidelinesException(sprintf('%s: a source name is lowercase letters, digits and hyphens, starting with a letter or digit.', $where));
        }
        if ( ! is_array($data) || array_is_list($data)) {
            throw new GuidelinesException(sprintf('%s must be an object with "url" and "ref".', $where));
        }
        $unknown = array_diff(array_map(strval(...), array_keys($data)), ['url', 'ref']);
        if ($unknown !== []) {
            throw new GuidelinesException(sprintf('%s: unknown key "%s" (allowed: url, ref).', $where, implode('", "', $unknown)));
        }
        $url = $data['url'] ?? null;
        $ref = $data['ref'] ?? self::DEFAULT_REF;
        if ( ! is_string($url) || trim($url) === '' || str_starts_with(trim($url), '-')) {
            throw new GuidelinesException(sprintf('%s: "url" must be a non-empty git URL.', $where));
        }
        if ( ! is_string($ref) || trim($ref) === '' || str_starts_with(trim($ref), '-')) {
            throw new GuidelinesException(sprintf('%s: "ref" must be a branch or a tag.', $where));
        }

        return new self($name, trim($url), trim($ref));
    }

    /** @return array{url: string, ref: string} */
    public function toArray(): array {
        return ['url' => $this->url, 'ref' => $this->ref];
    }
}
