<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Layer\SourceFile;
use Esoul\Guidelines\Markdown\Document;
use Esoul\Guidelines\Merge\Merger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MergerTest extends TestCase
{
    private function source(string $layer, string $body, array $frontmatter = []): SourceFile
    {
        return new SourceFile($layer, 'testing', $layer . '/testing.md', $frontmatter, $body, hash('sha256', $body));
    }

    public function testLaterLayerContentIsAppendedInsideTheSameSectionWithAttribution(): void
    {
        $merged = new Merger()->merge([
            $this->source('general', "# Project guidelines - Testing\n\n## Writing tests\n\nGeneral rule.\n\n## Flaky tests\n\nFix them.\n"),
            $this->source('framework/laravel', "# Ignored\n\n## `Writing tests`\n\nLaravel rule.\n"),
        ])->markdown;

        self::assertSame(
            "# Project guidelines - Testing\n\n## Writing tests\n\nGeneral rule.\n\n<!-- from: framework/laravel -->\nLaravel rule.\n\n## Flaky tests\n\nFix them.",
            $merged,
        );
    }

    public function testNewSectionsAreAppendedAtTheEndAndFirstLayerDefinesOrder(): void
    {
        $merged = new Merger()->merge([
            $this->source('general', "# Project guidelines - Testing\n\n## B\n\nb\n\n## A\n\na\n"),
            $this->source('package/x', "## New\n\nnew\n\n## B\n\nmore b\n"),
        ])->markdown;

        self::assertSame(['B', 'A', 'New'], $this->headings($merged));
        self::assertStringContainsString("## New\n\n<!-- from: package/x -->\nnew", $merged);
    }

    public function testIntroIsConcatenatedAndH1ComesFromTheFirstLayer(): void
    {
        $merged = new Merger()->merge([
            $this->source('general', "# Project guidelines - Testing\n\nGeneral intro.\n\n## A\n\na\n"),
            $this->source('framework/laravel', "# Project guidelines - Other\n\nLaravel intro.\n"),
        ])->markdown;

        self::assertStringStartsWith("# Project guidelines - Testing\n\nGeneral intro.\n\n<!-- from: framework/laravel -->\nLaravel intro.\n\n## A", $merged);
        self::assertStringNotContainsString('Other', $merged);
    }

    public function testReplaceMarkerReplacesEarlierContent(): void
    {
        $merged = new Merger()->merge([
            $this->source('general', "# T\n\n## A\n\nold rule\n"),
            $this->source('framework/laravel', "## A\n\n<!-- replace -->\nnew rule\n"),
        ])->markdown;

        self::assertStringNotContainsString('old rule', $merged);
        self::assertStringNotContainsString('replace', $merged);
        self::assertStringContainsString("## A\n\n<!-- from: framework/laravel -->\nnew rule", $merged);
    }

    public function testHeadingsInsideCodeFencesAreNotSections(): void
    {
        $merged = new Merger()->merge([
            $this->source('general', "# T\n\n## A\n\n```md\n## Not a heading\n```\n"),
            $this->source('framework/laravel', "## Not a heading\n\nreal section\n"),
        ])->markdown;

        self::assertSame(['A', 'Not a heading'], $this->headings($merged));
        self::assertStringContainsString("```md\n## Not a heading\n```", $merged);
    }

    #[DataProvider('metadata')]
    public function testFrontmatterIsMerged(array $first, array $second, string $title, int $order, bool $agents): void
    {
        $merged = new Merger()->merge([$this->source('general', "# T\n", $first), $this->source('framework/laravel', "# T\n", $second)]);

        self::assertSame($title, $merged->title);
        self::assertSame($order, $merged->order);
        self::assertSame($agents, $merged->agents);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, string, int, bool}> */
    public static function metadata(): iterable
    {
        yield 'first layer wins title and order' => [['title' => 'A', 'order' => 5], ['title' => 'B', 'order' => 1], 'A', 5, false];
        yield 'agents is OR-ed' => [['title' => 'A'], ['agents' => true], 'A', 1000, true];
    }

    /** @return list<string> */
    private function headings(string $markdown): array
    {
        return array_map(static fn ($s): string => $s->heading, Document::parse($markdown)->sections);
    }
}
