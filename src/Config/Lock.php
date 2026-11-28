<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Config;

use Esoul\Guidelines\GuidelinesException;
use JsonException;

/**
 * `esoul-guidelines.lock` (committed): what was last written, so local edits inside managed
 * blocks can be told apart from outdated content.
 */
final readonly class Lock
{
    public const string FILE = 'esoul-guidelines.lock';

    /** Written by `sync`. Schema 1 (no `sources`) is still read. */
    public const int SCHEMA_VERSION = 2;

    private const int OLDEST_SUPPORTED_SCHEMA = 1;

    /**
     * @param list<string> $layers
     * @param list<string> $exclude
     * @param array<string, LockEntry> $files output path (relative to the project root) => entry
     * @param array<int|string, LockSource> $sources guideline source name (an int key for a numeric name) => where its committed copy came from
     */
    public function __construct(
        public string $packageVersion,
        public array $layers,
        public array $exclude,
        public array $files,
        public array $sources = [],
    ) {
    }

    public static function load(string $root): ?self {
        $path = $root . '/' . self::FILE;
        if ( ! is_file($path)) {
            return null;
        }

        try {
            $json = (string) file_get_contents($path);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GuidelinesException(sprintf('%s is not valid JSON: %s', self::FILE, $e->getMessage()), 0, $e);
        }
        $schema = is_array($data) ? ($data['schema_version'] ?? null) : null;
        if ( ! is_array($data) || ! is_int($schema) || $schema < self::OLDEST_SUPPORTED_SCHEMA || $schema > self::SCHEMA_VERSION || ! is_array($data['files'] ?? null)) {
            throw new GuidelinesException(sprintf('%s has an unsupported format (expected schema_version %d or %d).', self::FILE, self::OLDEST_SUPPORTED_SCHEMA, self::SCHEMA_VERSION));
        }

        $files = [];
        foreach ($data['files'] as $path => $entry) {
            if ( ! is_array($entry) || ! is_string($entry['block'] ?? null) || ! is_string($entry['sha256'] ?? null)) {
                throw new GuidelinesException(sprintf('%s: invalid entry for "%s".', self::FILE, $path));
            }
            $files[(string) $path] = new LockEntry($entry['block'], $entry['sha256'], self::stringMap(array_key_exists('sources', $entry) ? $entry['sources'] : [], sprintf('sources of "%s"', $path)));
        }

        // Defaults only for a missing key: an explicit null is invalid and goes to the type checks.
        $packageVersion = array_key_exists('package_version', $data) ? $data['package_version'] : 'dev';
        if ( ! is_string($packageVersion)) {
            throw new GuidelinesException(sprintf('%s: "package_version" must be a string.', self::FILE));
        }

        return new self(
            $packageVersion,
            self::stringList(array_key_exists('layers', $data) ? $data['layers'] : [], 'layers'),
            self::stringList(array_key_exists('exclude', $data) ? $data['exclude'] : [], 'exclude'),
            $files,
            $schema >= 2 ? self::sources(array_key_exists('sources', $data) ? $data['sources'] : [], JsonObjectKeys::isObject($json, 'sources')) : [],
        );
    }

    /** @return array<string, LockSource> */
    private static function sources(mixed $value, bool $isObject): array {
        if ( ! is_array($value) || ($value !== [] && ! $isObject)) {
            throw new GuidelinesException(sprintf('%s: "sources" must be an object.', self::FILE));
        }

        $sources = [];
        foreach ($value as $name => $entry) {
            if ( ! is_array($entry) || ! is_string($entry['url'] ?? null) || ! is_string($entry['ref'] ?? null) || ! is_string($entry['commit'] ?? null)) {
                throw new GuidelinesException(sprintf('%s: invalid entry for the source "%s" (needs "url", "ref", "commit" and "files").', self::FILE, $name));
            }
            $sources[(string) $name] = new LockSource(
                $entry['url'],
                $entry['ref'],
                $entry['commit'],
                self::stringMap(array_key_exists('files', $entry) ? $entry['files'] : [], sprintf('files of the source "%s"', $name)),
            );
        }

        return $sources;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $what): array {
        if ( ! is_array($value) || ! array_is_list($value)) {
            throw new GuidelinesException(sprintf('%s: "%s" must be a list of strings.', self::FILE, $what));
        }

        $list = [];
        foreach ($value as $item) {
            if ( ! is_string($item)) {
                throw new GuidelinesException(sprintf('%s: "%s" must be a list of strings.', self::FILE, $what));
            }
            $list[] = $item;
        }

        return $list;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value, string $what): array {
        if ( ! is_array($value)) {
            throw new GuidelinesException(sprintf('%s: %s must be a map of strings.', self::FILE, $what));
        }

        $map = [];
        foreach ($value as $key => $item) {
            if ( ! is_string($item)) {
                throw new GuidelinesException(sprintf('%s: %s must be a map of strings.', self::FILE, $what));
            }
            $map[(string) $key] = $item;
        }

        return $map;
    }

    public function toJson(): string {
        $files = [];
        $paths = array_keys($this->files);
        sort($paths);
        foreach ($paths as $path) {
            $entry = $this->files[$path];
            $files[$path] = ['block' => $entry->block, 'sha256' => $entry->sha256]
                + ($entry->sources !== [] ? ['sources' => $entry->sources] : []);
        }

        $sources = [];
        $names = array_map(strval(...), array_keys($this->sources)); // a name like "0" is an int key
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            $sources[$name] = $this->sources[$name]->toArray();
        }

        $document = [
            'schema_version' => self::SCHEMA_VERSION,
            'package_version' => $this->packageVersion,
            'layers' => $this->layers,
            'exclude' => $this->exclude,
        ];
        if ($sources !== []) {
            $document['sources'] = (object) $sources; // an object even for a source named "0"
        }
        $document['files'] = $files;

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
