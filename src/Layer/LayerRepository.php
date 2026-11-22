<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Layer;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Markdown\Frontmatter;

/**
 * Reads guideline layers from `<baseDir>/<layer>/*.md` where a layer id is `general`
 * or `<group>/<name>` (`framework/laravel`, `package/filament`, ...).
 */
final class LayerRepository
{
    public const string GENERAL = 'general';

    public const string RESERVED_INDEX = 'index';

    private const string LAYER_PATTERN = '#^[a-z0-9][a-z0-9-]*(/[a-z0-9][a-z0-9-]*)?$#';

    /** @var list<string>|null */
    private ?array $layers = null;

    public function __construct(private readonly string $baseDir) {
    }

    public static function bundled(): self {
        return new self(dirname(__DIR__, 2) . '/resources/guidelines');
    }

    /**
     * @return list<string> `general` first, then `framework/*`, then `package/*`, then the rest.
     */
    public function layers(): array {
        if ($this->layers !== null) {
            return $this->layers;
        }

        $layers = [];
        foreach ($this->childDirs($this->baseDir) as $top) {
            if ($this->hasMarkdown($this->baseDir . '/' . $top)) {
                $layers[] = $top;
            }
            foreach ($this->childDirs($this->baseDir . '/' . $top) as $child) {
                if ($this->hasMarkdown($this->baseDir . '/' . $top . '/' . $child)) {
                    $layers[] = $top . '/' . $child;
                }
            }
        }

        $rank = static fn (string $layer): int => match (true) {
            $layer === self::GENERAL => 0,
            str_starts_with($layer, 'framework/') => 1,
            str_starts_with($layer, 'package/') => 2,
            default => 3,
        };
        usort($layers, static fn (string $a, string $b): int => [$rank($a), $a] <=> [$rank($b), $b]);

        return $this->layers = $layers;
    }

    public function has(string $layer): bool {
        return in_array($layer, $this->layers(), true);
    }

    /**
     * @return list<SourceFile> ordered by file name
     */
    public function files(string $layer): array {
        if (preg_match(self::LAYER_PATTERN, $layer) !== 1 || ! $this->has($layer)) {
            throw new GuidelinesException(sprintf('Unknown guideline layer "%s". Available: %s.', $layer, implode(', ', $this->layers())));
        }

        $dir = $this->baseDir . '/' . $layer;
        $names = [];
        foreach (self::entries($dir) as $entry) {
            if (str_ends_with($entry, '.md') && is_file($dir . '/' . $entry)) {
                $names[] = substr($entry, 0, -3);
            }
        }
        sort($names);

        $files = [];
        foreach ($names as $name) {
            if ($name === self::RESERVED_INDEX) {
                throw new GuidelinesException(sprintf('Layer "%s" ships "index.md", which is reserved for the generated index.', $layer));
            }
            $raw = file_get_contents($dir . '/' . $name . '.md');
            if ($raw === false) {
                throw new GuidelinesException(sprintf('Cannot read %s/%s.md.', $layer, $name));
            }
            [$frontmatter, $body] = Frontmatter::parse($raw, $layer . '/' . $name . '.md');
            $files[] = new SourceFile($layer, $name, $layer . '/' . $name . '.md', $frontmatter, $body, hash('sha256', $raw));
        }

        return $files;
    }

    /** @return list<string> */
    private static function entries(string $dir): array {
        $entries = scandir($dir);

        return $entries === false ? [] : $entries;
    }

    /** @return list<string> */
    private function childDirs(string $dir): array {
        if ( ! is_dir($dir)) {
            return [];
        }
        $dirs = [];
        foreach (self::entries($dir) as $entry) {
            if ($entry[0] !== '.' && is_dir($dir . '/' . $entry)) {
                $dirs[] = $entry;
            }
        }

        return $dirs;
    }

    private function hasMarkdown(string $dir): bool {
        return glob($dir . '/*.md') !== [] && glob($dir . '/*.md') !== false;
    }
}
