<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

use Esoul\Guidelines\GuidelinesException;

/**
 * Locates and replaces `<!-- esoul-guidelines:begin NAME --> ... <!-- esoul-guidelines:end NAME -->`
 * blocks. Text outside the block is never altered.
 */
final class ManagedBlock
{
    public static function begin(string $name): string {
        return '<!-- esoul-guidelines:begin ' . $name . ' -->';
    }

    public static function end(string $name): string {
        return '<!-- esoul-guidelines:end ' . $name . ' -->';
    }

    /** Hash of the block content; line endings and surrounding blank lines are ignored. */
    public static function hash(string $content): string {
        return hash('sha256', self::normalise($content));
    }

    public static function normalise(string $content): string {
        return trim(str_replace("\r\n", "\n", $content), "\n");
    }

    public static function render(string $name, string $content): string {
        return self::begin($name) . "\n" . self::normalise($content) . "\n" . self::end($name);
    }

    /**
     * @return array{start: int, end: int, content: string}|null byte offsets of the whole block (markers included)
     */
    public static function find(string $text, string $name): ?array {
        $begin = self::begin($name);
        $start = strpos($text, $begin);
        if ($start === false) {
            return null;
        }
        $end = self::end($name);
        $endPos = strpos($text, $end, $start + strlen($begin));
        if ($endPos === false) {
            throw new GuidelinesException(sprintf('Unterminated managed block "%s": missing "%s".', $name, $end));
        }

        return [
            'start' => $start,
            'end' => $endPos + strlen($end),
            'content' => self::normalise(substr($text, $start + strlen($begin), $endPos - $start - strlen($begin))),
        ];
    }

    /** Replaces the block in place, or returns null when the text has no such block. */
    public static function replace(string $text, string $name, string $content): ?string {
        $found = self::find($text, $name);
        if ($found === null) {
            return null;
        }

        return substr($text, 0, $found['start']) . self::render($name, $content) . substr($text, $found['end']);
    }

    /** Removes the block and the blank-line gap it leaves. Returns the text unchanged without a block. */
    public static function remove(string $text, string $name): string {
        $found = self::find($text, $name);
        if ($found === null) {
            return $text;
        }
        $before = rtrim(substr($text, 0, $found['start']));
        $after = ltrim(substr($text, $found['end']));
        if ($before === '' || $after === '') {
            $rest = rtrim($before . $after);

            return $rest === '' ? '' : $rest . "\n";
        }

        return $before . "\n\n" . $after;
    }
}
