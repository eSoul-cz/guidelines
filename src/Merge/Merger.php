<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Merge;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\SourceFile;
use Esoul\Guidelines\Markdown\Document;

/**
 * Merges same-named guideline files of several layers into one document.
 *
 * - H1 comes from the first layer; the first layer defines the section order.
 * - Sections are keyed by normalised H2 text. A later layer's same-key content is appended inside
 *   the section, attributed with `<!-- from: <layer> -->`; new keys are appended at the end.
 * - Content before the first H2 (intro) is concatenated the same way.
 * - A later-layer section/intro starting with `<!-- replace -->` replaces the earlier content.
 */
final class Merger
{
    public const string REPLACE_MARKER = '<!-- replace -->';

    private const int DEFAULT_ORDER = 1000;

    /**
     * @param list<SourceFile> $sources same file name, in layer order (at least one)
     */
    public function merge(array $sources): MergedGuideline
    {
        if ($sources === []) {
            throw new GuidelinesException('Cannot merge an empty list of sources.');
        }

        $primary = $sources[0];
        $docs = array_map(static fn (SourceFile $s): Document => Document::parse($s->body), $sources);

        /** @var list<array{0: string, 1: string}> $introParts */
        $introParts = [];
        /** @var array<string, array{heading: string, parts: list<array{0: string, 1: string}>}> $sections */
        $sections = [];

        foreach ($sources as $i => $source) {
            $doc = $docs[$i];
            if ($doc->intro !== '') {
                $introParts[] = [$source->layer, $doc->intro];
            }
            foreach ($doc->sections as $section) {
                $sections[$section->key] ??= ['heading' => $section->heading, 'parts' => []];
                if ($section->body !== '') {
                    $sections[$section->key]['parts'][] = [$source->layer, $section->body];
                }
            }
        }

        $title = null;
        $order = null;
        $summary = null;
        $agents = false;
        foreach ($sources as $source) {
            // Frontmatter::parse() already rejected values of any other type.
            $sourceTitle = $source->frontmatter['title'] ?? null;
            $sourceOrder = $source->frontmatter['order'] ?? null;
            $sourceSummary = $source->frontmatter['summary'] ?? null;
            if ($title === null && is_string($sourceTitle)) {
                $title = $sourceTitle;
            }
            if ($order === null && is_int($sourceOrder)) {
                $order = $sourceOrder;
            }
            if ($summary === null && is_string($sourceSummary)) {
                $summary = $sourceSummary;
            }
            $agents = $agents || ($source->frontmatter['agents'] ?? false) === true;
        }
        $title ??= $this->titleFromH1($docs[0]->h1) ?? $primary->name;
        $h1 = $docs[0]->h1 ?? 'Project guidelines - ' . $title;

        $out = ['# ' . $h1];
        $intro = $this->joinParts($introParts, $primary->layer);
        if ($intro !== '') {
            $out[] = $intro;
        }
        foreach ($sections as $section) {
            $out[] = '## ' . $section['heading'];
            $body = $this->joinParts($section['parts'], $primary->layer);
            if ($body !== '') {
                $out[] = $body;
            }
        }

        return new MergedGuideline(
            name: $primary->name,
            title: $title,
            order: $order ?? self::DEFAULT_ORDER,
            summary: $summary ?? '',
            agents: $agents,
            layers: array_values(array_unique(array_map(static fn (SourceFile $s): string => $s->layer, $sources))),
            sources: array_column(array_map(static fn (SourceFile $s): array => [$s->relativePath, $s->hash], $sources), 1, 0),
            markdown: implode("\n\n", $out),
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $parts [layer, text]
     */
    private function joinParts(array $parts, string $primaryLayer): string
    {
        $rendered = [];
        foreach ($parts as [$layer, $text]) {
            $replace = false;
            if (str_starts_with(ltrim($text), self::REPLACE_MARKER)) {
                $replace = true;
                $text = ltrim(substr(ltrim($text), strlen(self::REPLACE_MARKER)), "\n");
                if ($text === '') {
                    continue;
                }
            }
            if ($replace && $layer !== $primaryLayer) {
                $rendered = [];
            }
            $rendered[] = ($layer !== $primaryLayer ? '<!-- from: ' . $layer . ' -->' . "\n" : '') . $text;
        }

        return implode("\n\n", $rendered);
    }

    private function titleFromH1(?string $h1): ?string
    {
        if ($h1 === null) {
            return null;
        }

        $title = preg_replace('/^Project guidelines\s*[-–—:]\s*/i', '', $h1);

        return $title === null || $title === '' ? $h1 : $title;
    }
}
