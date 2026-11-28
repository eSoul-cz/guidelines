<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Layer;

/**
 * A layer id is `general` or `<group>/<name>` for a bundled layer, and `<source>:<layer>` for a layer of a guideline
 * source (`acme:package/sso`). A source name is lowercase `[a-z0-9][a-z0-9-]*`.
 */
final class LayerId
{
    public const string SOURCE_PATTERN = '[a-z0-9][a-z0-9-]*';

    /** The source of a prefixed id; null for a bundled layer. */
    public static function source(string $id): ?string {
        $pos = strpos($id, ':');

        return $pos === false ? null : substr($id, 0, $pos);
    }

    /** The id without its source prefix. */
    public static function bare(string $id): string {
        $pos = strpos($id, ':');

        return $pos === false ? $id : substr($id, $pos + 1);
    }

    public static function join(string $source, string $layer): string {
        return $source . ':' . $layer;
    }

    public static function isValidSource(string $name): bool {
        return preg_match('#^' . self::SOURCE_PATTERN . '$#', $name) === 1;
    }

    /**
     * @param list<string> $layers config layer ids
     * @return list<string> the unprefixed ids of the layers of one source, in config order
     */
    public static function selected(array $layers, string $source): array {
        $selected = [];
        foreach ($layers as $layer) {
            if (self::source($layer) === $source) {
                $selected[] = self::bare($layer);
            }
        }

        return $selected;
    }
}
