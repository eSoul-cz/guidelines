<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Config;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerId;
use Esoul\Guidelines\Layer\LayerRepository;
use JsonException;

/**
 * `esoul-guidelines.json` in the project root:
 * `{"sources": {...}, "layers": [...], "exclude": [...], "exceptions": [...]}`.
 */
final readonly class ProjectConfig
{
    public const string FILE = 'esoul-guidelines.json';

    /**
     * @param list<string> $layers `general` first, then the project's layers in config order
     * @param list<string> $exclude output file names without `.md`
     * @param list<RuleException> $exceptions recorded project exceptions to guideline rules
     * @param array<string, SourceSpec> $sources guideline sources by name, in config order; their layers are `<source>:<layer>`
     */
    public function __construct(
        public array $layers,
        public array $exclude = [],
        public array $exceptions = [],
        public array $sources = [],
    ) {
    }

    /**
     * @param list<string> $layers
     * @param list<string> $exclude
     * @param list<RuleException> $exceptions
     * @param array<string, SourceSpec> $sources
     */
    public static function create(array $layers, array $exclude = [], array $exceptions = [], array $sources = []): self {
        $layers = array_values(array_unique([LayerRepository::GENERAL, ...$layers]));
        $exclude = array_values(array_unique(array_map(
            static fn (string $name): string => str_ends_with($name, '.md') ? substr($name, 0, -3) : $name,
            $exclude,
        )));

        return new self($layers, $exclude, $exceptions, $sources);
    }

    public static function exists(string $root): bool {
        return is_file($root . '/' . self::FILE);
    }

    public static function load(string $root): self {
        $path = $root . '/' . self::FILE;
        if ( ! is_file($path)) {
            throw new GuidelinesException(sprintf('%s not found in %s. Run `esoul-guidelines init` first.', self::FILE, $root));
        }

        $json = (string) file_get_contents($path);
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GuidelinesException(sprintf('%s is not valid JSON: %s', self::FILE, $e->getMessage()), 0, $e);
        }
        if ( ! is_array($data)) {
            throw new GuidelinesException(sprintf('%s must contain a JSON object.', self::FILE));
        }

        $layers = $data['layers'] ?? [];
        $exclude = $data['exclude'] ?? [];
        if ( ! self::isStringList($layers) || ! self::isStringList($exclude)) {
            throw new GuidelinesException(sprintf('"layers" and "exclude" in %s must be lists of strings.', self::FILE));
        }

        $sources = self::parseSources($data['sources'] ?? [], JsonObjectKeys::duplicates($json, 'sources'));
        $exceptions = self::parseExceptions($data['exceptions'] ?? []);
        self::assertKnownSources($layers, $exceptions, $sources);

        return self::create($layers, $exclude, $exceptions, $sources);
    }

    /**
     * @param list<string> $duplicates keys that the `sources` object repeats
     * @return array<string, SourceSpec>
     */
    private static function parseSources(mixed $value, array $duplicates): array {
        if ($duplicates !== []) {
            throw new GuidelinesException(sprintf('"sources" in %s lists the source "%s" more than once. Two sources cannot have the same name.', self::FILE, $duplicates[0]));
        }
        if ( ! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new GuidelinesException(sprintf('"sources" in %s must be an object: {"<name>": {"url": "...", "ref": "main"}}.', self::FILE));
        }

        $sources = [];
        foreach ($value as $name => $spec) {
            $sources[(string) $name] = SourceSpec::fromArray((string) $name, $spec);
        }

        return $sources;
    }

    /**
     * @param list<string> $layers
     * @param list<RuleException> $exceptions
     * @param array<string, SourceSpec> $sources
     */
    private static function assertKnownSources(array $layers, array $exceptions, array $sources): void {
        foreach ($layers as $layer) {
            $source = LayerId::source($layer);
            if ($source !== null && ! isset($sources[$source])) {
                throw new GuidelinesException(sprintf('Layer "%s" in %s has the prefix "%s:", but no source "%s" is configured under "sources". Add it with `esoul-guidelines source:add <url>` or fix the prefix.', $layer, self::FILE, $source, $source));
            }
        }
        foreach ($exceptions as $i => $exception) {
            $source = $exception->source();
            if ($source !== null && ! isset($sources[$source])) {
                throw new GuidelinesException(sprintf('exceptions[%d] in %s: rule "%s" has the prefix "%s:", but no source "%s" is configured under "sources".', $i, self::FILE, $exception->rule, $source, $source));
            }
        }
    }

    /**
     * @return list<RuleException>
     */
    private static function parseExceptions(mixed $value): array {
        if ( ! is_array($value) || ! array_is_list($value)) {
            throw new GuidelinesException(sprintf('"exceptions" in %s must be a list of objects with "rule", "instead" and "adr".', self::FILE));
        }

        $exceptions = [];
        foreach ($value as $i => $item) {
            if ( ! is_array($item) || array_is_list($item)) {
                throw new GuidelinesException(sprintf('exceptions[%d] in %s must be an object with "rule", "instead" and "adr".', $i, self::FILE));
            }
            $exception = RuleException::fromArray($item, $i);
            if (array_any($exceptions, static fn (RuleException $other): bool => $other->rule === $exception->rule)) {
                throw new GuidelinesException(sprintf('exceptions[%d] in %s repeats the rule "%s".', $i, self::FILE, $exception->rule));
            }
            $exceptions[] = $exception;
        }

        return $exceptions;
    }

    public function save(string $root): void {
        $data = [
            'sources' => array_map(static fn (SourceSpec $s): array => $s->toArray(), $this->sources),
            'layers' => $this->layers,
            'exclude' => $this->exclude,
            'exceptions' => array_map(static fn (RuleException $e): array => $e->toArray(), $this->exceptions),
        ];
        if ($this->sources === []) {
            unset($data['sources']);
        }
        if ($this->exclude === []) {
            unset($data['exclude']);
        }
        if ($this->exceptions === []) {
            unset($data['exceptions']);
        }
        file_put_contents($root . '/' . self::FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @phpstan-assert-if-true list<string> $value */
    private static function isStringList(mixed $value): bool {
        return is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $v): bool => is_string($v));
    }
}
