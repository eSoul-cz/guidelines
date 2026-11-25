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
 * - A later-layer section/intro starting with `<!-- replace -->` replaces the earlier content;
 *   `<!-- replace: <layer>[, <layer>...] -->` replaces only the named layers' content, in its place.
 */
final class Merger
{
    public const string REPLACE_MARKER = '<!-- replace -->';

    private const int DEFAULT_ORDER = 1000;

    /**
     * @param list<SourceFile> $sources same file name, in layer order (at least one)
     */
    public function merge(array $sources): MergedGuideline {
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
    private function joinParts(array $parts, string $primaryLayer): string {
        /** @var list<array{0: string, 1: string}> $rendered [layer, text] */
        $rendered = [];
        foreach ($parts as [$layer, $text]) {
            $replace = false;
            $targets = [];
            if (preg_match(Document::REPLACE_PATTERN, ltrim($text), $match) === 1) {
                $replace = true;
                $targets = ($match[1] ?? '') !== '' ? preg_split('/\s*,\s*/', $match[1], -1, PREG_SPLIT_NO_EMPTY) : [];
                $text = ltrim(substr(ltrim($text), strlen($match[0])), "\n");
            }
            $part = [$layer, ($layer !== $primaryLayer ? '<!-- from: ' . $layer . ' -->' . "\n" : '') . $text];

            if ($targets !== [] && $targets !== false) {
                $rendered = $this->replaceLayers($rendered, $targets, $text === '' ? null : $part);

                continue;
            }
            if ($replace && $text === '') {
                continue;
            }
            if ($replace && $layer !== $primaryLayer) {
                $rendered = [];
            }
            $rendered[] = $part;
        }

        return implode("\n\n", array_column($rendered, 1));
    }

    /**
     * Puts `$part` where the first target layer's content was (all the targets' content is removed), or at
     * the end when no target contributed; a null part only removes the targets' content.
     *
     * @param list<array{0: string, 1: string}> $rendered [layer, text]
     * @param list<string> $targets
     * @param array{0: string, 1: string}|null $part
     * @return list<array{0: string, 1: string}>
     */
    private function replaceLayers(array $rendered, array $targets, ?array $part): array {
        $result = [];
        $placed = false;
        foreach ($rendered as $existing) {
            if ( ! in_array($existing[0], $targets, true)) {
                $result[] = $existing;
            } elseif ( ! $placed && $part !== null) {
                $result[] = $part;
                $placed = true;
            }
        }
        if ( ! $placed && $part !== null) {
            $result[] = $part;
        }

        return $result;
    }

    private function titleFromH1(?string $h1): ?string {
        if ($h1 === null) {
            return null;
        }

        $title = preg_replace('/^Project guidelines\s*[-–—:]\s*/i', '', $h1);

        return $title === null || $title === '' ? $h1 : $title;
    }
}
