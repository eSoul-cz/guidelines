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

    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $layers
     * @param list<string> $exclude
     * @param array<string, LockEntry> $files output path (relative to the project root) => entry
     */
    public function __construct(
        public string $packageVersion,
        public array $layers,
        public array $exclude,
        public array $files,
    ) {
    }

    public static function load(string $root): ?self {
        $path = $root . '/' . self::FILE;
        if ( ! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GuidelinesException(sprintf('%s is not valid JSON: %s', self::FILE, $e->getMessage()), 0, $e);
        }
        if ( ! is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION || ! is_array($data['files'] ?? null)) {
            throw new GuidelinesException(sprintf('%s has an unsupported format (expected schema_version %d).', self::FILE, self::SCHEMA_VERSION));
        }

        $files = [];
        foreach ($data['files'] as $path => $entry) {
            if ( ! is_array($entry) || ! is_string($entry['block'] ?? null) || ! is_string($entry['sha256'] ?? null)) {
                throw new GuidelinesException(sprintf('%s: invalid entry for "%s".', self::FILE, $path));
            }
            $files[(string) $path] = new LockEntry($entry['block'], $entry['sha256'], self::stringMap($entry['sources'] ?? [], sprintf('sources of "%s"', $path)));
        }

        $packageVersion = $data['package_version'] ?? 'dev';
        if ( ! is_string($packageVersion)) {
            throw new GuidelinesException(sprintf('%s: "package_version" must be a string.', self::FILE));
        }

        return new self(
            $packageVersion,
            self::stringList($data['layers'] ?? [], 'layers'),
            self::stringList($data['exclude'] ?? [], 'exclude'),
            $files,
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $what): array {
        if ( ! is_array($value)) {
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

        return json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'package_version' => $this->packageVersion,
            'layers' => $this->layers,
            'exclude' => $this->exclude,
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
