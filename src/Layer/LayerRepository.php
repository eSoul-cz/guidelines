<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Layer;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Markdown\Frontmatter;
use Esoul\Guidelines\Source\CopyTree;

/**
 * Reads guideline layers from `<baseDir>/<layer>/*.md` where a layer id is `general`
 * or `<group>/<name>` (`profile/internal`, `framework/laravel`, `package/filament`, ...).
 *
 * With a `$source` the repository reads the layers of a guideline source: every id it reports and accepts carries
 * the prefix (`acme:package/sso`), and so do the layer and the path of its source files.
 */
final class LayerRepository
{
    public const string GENERAL = 'general';

    public const string PROFILE_INTERNAL = 'profile/internal';

    public const string RESERVED_INDEX = 'index';

    private const string LAYER_PATTERN = '#^[a-z0-9][a-z0-9-]*(/[a-z0-9][a-z0-9-]*)?$#';

    /** @var list<string>|null */
    private ?array $layers = null;

    public function __construct(
        private readonly string $baseDir,
        private readonly ?string $source = null,
    ) {
    }

    public static function bundled(): self {
        return new self(dirname(__DIR__, 2) . '/resources/guidelines');
    }

    /**
     * @return list<string> `general` first, then `profile/*`, `framework/*`, `package/*`, then the rest (prefixed with the source).
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
            str_starts_with($layer, 'profile/') => 1,
            str_starts_with($layer, 'framework/') => 2,
            str_starts_with($layer, 'package/') => 3,
            default => 4,
        };
        usort($layers, static fn (string $a, string $b): int => [$rank($a), $a] <=> [$rank($b), $b]);
        if (($source = $this->source) !== null) {
            $layers = array_map(static fn (string $layer): string => LayerId::join($source, $layer), $layers);
        }

        return $this->layers = $layers;
    }

    public function has(string $layer): bool {
        return in_array($layer, $this->layers(), true);
    }

    /**
     * @return list<SourceFile> ordered by file name
     */
    public function files(string $layer): array {
        $bare = $this->source === null ? $layer : LayerId::bare($layer);
        if (preg_match(self::LAYER_PATTERN, $bare) !== 1 || ! $this->has($layer)) {
            throw new GuidelinesException(sprintf('Unknown guideline layer "%s". Available: %s.', $layer, implode(', ', $this->layers())));
        }

        $dir = $this->baseDir . '/' . $bare;
        $names = [];
        foreach (self::entries($dir) as $entry) {
            if ( ! str_ends_with($entry, '.md') || ! file_exists($dir . '/' . $entry)) {
                continue;
            }
            // A guideline source is never read through a symlink: it could point anywhere on the machine.
            if ($this->source !== null && ! CopyTree::isPlain($this->baseDir, $bare . '/' . $entry, false)) {
                throw new GuidelinesException(sprintf('%s/%s is a symlink or lies behind one; guideline sources are never read through symlinks.', $layer, $entry));
            }
            if (is_file($dir . '/' . $entry)) {
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
        // Not glob(): it does not support the phar:// stream wrapper, where the bundled layers live in the PHAR.
        foreach (self::entries($dir) as $entry) {
            if (str_ends_with($entry, '.md') && is_file($dir . '/' . $entry)) {
                return true;
            }
        }

        return false;
    }
}
