<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Markdown;

/** A guideline body split into H1, intro (before the first H2) and H2 sections. */
final readonly class Document
{
    /**
     * @param list<Section> $sections
     */
    public function __construct(
        public ?string $h1,
        public string $intro,
        public array $sections,
    ) {
    }

    /** Fence-aware split. `##` inside fenced code blocks is not a heading. */
    public static function parse(string $body): self
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $body));

        $h1 = null;
        /** @var list<string> $introLines */
        $introLines = [];
        /** @var list<array{0: string, 1: list<string>}> $sections */
        $sections = [];
        $current = null; // index into $sections
        $fence = null;   // [char, length] while inside a fenced block

        foreach ($lines as $line) {
            if ($fence !== null) {
                if (preg_match('/^\s{0,3}(' . preg_quote($fence[0], '/') . '{' . $fence[1] . ',})\s*$/', $line) === 1) {
                    $fence = null;
                }
            } elseif (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $fence = [$m[1][0], strlen($m[1])];
            } elseif (preg_match('/^## +(.+?)(?: +#+)?\s*$/', $line, $m) === 1) {
                $sections[] = [trim($m[1]), []];
                $current = array_key_last($sections);
                continue;
            } elseif ($h1 === null && $current === null && trim(implode('', $introLines)) === '' && preg_match('/^# +(.+?)\s*$/', $line, $m) === 1) {
                $h1 = $m[1];
                continue;
            }

            if ($current === null) {
                $introLines[] = $line;
            } else {
                $sections[$current][1][] = $line;
            }
        }

        $built = [];
        foreach ($sections as [$heading, $sectionLines]) {
            $built[] = new Section($heading, Section::normaliseKey($heading), self::trimBlank(implode("\n", $sectionLines)));
        }

        return new self($h1, self::trimBlank(implode("\n", $introLines)), $built);
    }

    private static function trimBlank(string $text): string
    {
        return rtrim(ltrim($text, "\n"));
    }
}
