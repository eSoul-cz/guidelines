<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Markdown;

use Esoul\Guidelines\GuidelinesException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Splits an optional leading YAML frontmatter block from a Markdown file. */
final class Frontmatter
{
    /**
     * @return array{0: array<string, mixed>, 1: string} [frontmatter, body]
     */
    public static function parse(string $raw, string $origin = 'file'): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (!str_starts_with($raw, "---\n")) {
            return [[], $raw];
        }

        $end = strpos($raw, "\n---\n", 3);
        $tailOnly = false;
        if ($end === false) {
            if (!str_ends_with($raw, "\n---")) {
                return [[], $raw];
            }
            $end = strlen($raw) - 4;
            $tailOnly = true;
        }

        $yaml = substr($raw, 4, $end - 4);
        $body = $tailOnly ? '' : substr($raw, $end + 5);

        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new GuidelinesException(sprintf('Invalid frontmatter in %s: %s', $origin, $e->getMessage()), 0, $e);
        }
        if ($data === null) {
            $data = [];
        }
        if (!is_array($data)) {
            throw new GuidelinesException(sprintf('Frontmatter of %s must be a mapping.', $origin));
        }

        self::assertType($data, 'title', 'is_string', 'a string', $origin);
        self::assertType($data, 'summary', 'is_string', 'a string', $origin);
        self::assertType($data, 'order', 'is_int', 'an integer', $origin);
        self::assertType($data, 'agents', 'is_bool', 'a boolean', $origin);

        $frontmatter = [];
        foreach ($data as $key => $value) {
            $frontmatter[(string) $key] = $value;
        }

        return [$frontmatter, $body];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param callable(mixed): bool $check
     */
    private static function assertType(array $data, string $key, callable $check, string $expected, string $origin): void
    {
        if (array_key_exists($key, $data) && !$check($data[$key])) {
            throw new GuidelinesException(sprintf('Frontmatter key "%s" of %s must be %s.', $key, $origin, $expected));
        }
    }
}
