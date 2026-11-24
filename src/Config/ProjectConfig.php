<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Config;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerRepository;
use JsonException;

/** `esoul-guidelines.json` in the project root: `{"layers": [...], "exclude": [...], "exceptions": [...]}`. */
final readonly class ProjectConfig
{
    public const string FILE = 'esoul-guidelines.json';

    /**
     * @param list<string> $layers `general` first, then the project's layers in config order
     * @param list<string> $exclude output file names without `.md`
     * @param list<RuleException> $exceptions recorded project exceptions to guideline rules
     */
    public function __construct(
        public array $layers,
        public array $exclude = [],
        public array $exceptions = [],
    ) {
    }

    /**
     * @param list<string> $layers
     * @param list<string> $exclude
     * @param list<RuleException> $exceptions
     */
    public static function create(array $layers, array $exclude = [], array $exceptions = []): self {
        $layers = array_values(array_unique([LayerRepository::GENERAL, ...$layers]));
        $exclude = array_values(array_unique(array_map(
            static fn (string $name): string => str_ends_with($name, '.md') ? substr($name, 0, -3) : $name,
            $exclude,
        )));

        return new self($layers, $exclude, $exceptions);
    }

    public static function exists(string $root): bool {
        return is_file($root . '/' . self::FILE);
    }

    public static function load(string $root): self {
        $path = $root . '/' . self::FILE;
        if ( ! is_file($path)) {
            throw new GuidelinesException(sprintf('%s not found in %s. Run `esoul-guidelines init` first.', self::FILE, $root));
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
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

        return self::create($layers, $exclude, self::parseExceptions($data['exceptions'] ?? []));
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
        $data = ['layers' => $this->layers, 'exclude' => $this->exclude, 'exceptions' => array_map(static fn (RuleException $e): array => $e->toArray(), $this->exceptions)];
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
