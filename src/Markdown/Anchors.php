<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Markdown;

/**
 * Heading anchors of a guideline source file, the stable reference of a rule (`<layer>/<file>.md#<anchor>`).
 *
 * The anchor is the GitHub slug of an H2, H3 or H4 heading (lower case, punctuation dropped, spaces become
 * hyphens, a repeated slug in one file gets `-1`, `-2`). Fence-aware, like {@see Document::parse()}.
 */
final class Anchors
{
    /**
     * @return list<array{line: int, anchor: string}> `line` is the index of the heading line in the body
     */
    public static function headings(string $body): array {
        $lines = explode("\n", str_replace("\r\n", "\n", $body));
        $found = [];
        $seen = [];
        $fence = null;

        foreach ($lines as $i => $line) {
            if ($fence !== null) {
                if (preg_match('/^\s{0,3}(' . preg_quote($fence[0], '/') . '{' . $fence[1] . ',})\s*$/', $line) === 1) {
                    $fence = null;
                }
                continue;
            }
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $fence = [$m[1][0], strlen($m[1])];
                continue;
            }
            if (preg_match('/^#{2,4} +(.+?)(?: +#+)?\s*$/', $line, $m) !== 1) {
                continue;
            }

            $slug = self::slug($m[1]);
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $found[] = ['line' => $i, 'anchor' => $count === 0 ? $slug : $slug . '-' . $count];
        }

        return $found;
    }

    public static function has(string $body, string $anchor): bool {
        return array_any(self::headings($body), static fn (array $heading): bool => $heading['anchor'] === $anchor);
    }

    /**
     * Inserts `$text` (one paragraph) directly under the heading with the given anchor.
     * When the section starts with `<!-- replace -->`, the text goes below that line so the merge still sees it first.
     *
     * @return string|null the new body, or null when no heading has the anchor
     */
    public static function insertUnder(string $body, string $anchor, string $text): ?string {
        $line = null;
        foreach (self::headings($body) as $heading) {
            if ($heading['anchor'] === $anchor) {
                $line = $heading['line'];
                break;
            }
        }
        if ($line === null) {
            return null;
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $body));
        $at = $line + 1;
        $next = $at;
        while (isset($lines[$next]) && trim($lines[$next]) === '') {
            ++$next;
        }
        if (isset($lines[$next]) && trim($lines[$next]) === '<!-- replace -->') {
            $at = $next + 1;
        }
        array_splice($lines, $at, 0, ['', $text]);

        return implode("\n", $lines);
    }

    public static function slug(string $heading): string {
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $heading) ?? $heading;
        $text = str_replace(['`', '*'], '', $text);
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\p{M}\s_-]/u', '', $text) ?? $text;

        return str_replace(' ', '-', $text);
    }
}
