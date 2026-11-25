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
    /** @param array<string, mixed> $frontmatter */
    private function source(string $layer, string $body, array $frontmatter = []): SourceFile {
        return new SourceFile($layer, 'testing', $layer . '/testing.md', $frontmatter, $body, hash('sha256', $body));
    }

    public function test_later_layer_content_is_appended_inside_the_same_section_with_attribution(): void {
        $merged = new Merger()->merge([
            $this->source('general', "# Project guidelines - Testing\n\n## Writing tests\n\nGeneral rule.\n\n## Flaky tests\n\nFix them.\n"),
            $this->source('framework/laravel', "# Ignored\n\n## `Writing tests`\n\nLaravel rule.\n"),
        ])->markdown;

        self::assertSame(
            "# Project guidelines - Testing\n\n## Writing tests\n\nGeneral rule.\n\n<!-- from: framework/laravel -->\nLaravel rule.\n\n## Flaky tests\n\nFix them.",
            $merged,
        );
    }

    public function test_new_sections_are_appended_at_the_end_and_first_layer_defines_order(): void {
        $merged = new Merger()->merge([
            $this->source('general', "# Project guidelines - Testing\n\n## B\n\nb\n\n## A\n\na\n"),
            $this->source('package/x', "## New\n\nnew\n\n## B\n\nmore b\n"),
        ])->markdown;

        self::assertSame(['B', 'A', 'New'], $this->headings($merged));
        self::assertStringContainsString("## New\n\n<!-- from: package/x -->\nnew", $merged);
    }

    public function test_intro_is_concatenated_and_h1_comes_from_the_first_layer(): void {
        $merged = new Merger()->merge([
            $this->source('general', "# Project guidelines - Testing\n\nGeneral intro.\n\n## A\n\na\n"),
            $this->source('framework/laravel', "# Project guidelines - Other\n\nLaravel intro.\n"),
        ])->markdown;

        self::assertStringStartsWith("# Project guidelines - Testing\n\nGeneral intro.\n\n<!-- from: framework/laravel -->\nLaravel intro.\n\n## A", $merged);
        self::assertStringNotContainsString('Other', $merged);
    }

    public function test_replace_marker_replaces_earlier_content(): void {
        $merged = new Merger()->merge([
            $this->source('general', "# T\n\n## A\n\nold rule\n"),
            $this->source('framework/laravel', "## A\n\n<!-- replace -->\nnew rule\n"),
        ])->markdown;

        self::assertStringNotContainsString('old rule', $merged);
        self::assertStringNotContainsString('replace', $merged);
        self::assertStringContainsString("## A\n\n<!-- from: framework/laravel -->\nnew rule", $merged);
    }

    public function test_a_replaced_section_still_receives_content_from_later_layers(): void {
        $merged = new Merger()->merge([
            $this->source('general', "# T\n\n## A\n\nold rule\n\n## B\n\nkept\n"),
            $this->source('profile/internal', "## A\n\n<!-- replace -->\nrelaxed rule\n"),
            $this->source('framework/laravel', "## A\n\nframework addition\n"),
        ])->markdown;

        self::assertSame(
            "# T\n\n## A\n\n<!-- from: profile/internal -->\nrelaxed rule\n\n<!-- from: framework/laravel -->\nframework addition\n\n## B\n\nkept",
            $merged,
        );
    }

    public function test_a_targeted_replace_marker_replaces_only_that_layers_content_in_its_place(): void {
        $merged = new Merger()->merge([
            $this->source('framework/laravel', "# T\n\n## A\n\nframework rule\n"),
            $this->source('package/client', "## A\n\nclient rule\n"),
            $this->source('package/server', "## A\n\nserver rule\n\n## B\n\nserver only\n"),
            $this->source('package/server-variant', "## A\n\n<!-- replace: package/server -->\nvariant rule\n\n## B\n\n<!-- replace: package/server -->\n"),
        ])->markdown;

        self::assertSame(
            "# T\n\n## A\n\nframework rule\n\n<!-- from: package/client -->\nclient rule\n\n<!-- from: package/server-variant -->\nvariant rule\n\n## B",
            $merged,
        );
    }

    public function test_a_targeted_replace_marker_keeps_the_place_of_the_replaced_content(): void {
        $merged = new Merger()->merge([
            $this->source('general', "# T\n\n## A\n\ngeneral rule\n"),
            $this->source('package/server', "## A\n\nserver rule\n"),
            $this->source('package/other', "## A\n\nother rule\n"),
            $this->source('package/server-variant', "## A\n\n<!-- replace: package/server -->\nvariant rule\n"),
        ])->markdown;

        self::assertSame(
            "# T\n\n## A\n\ngeneral rule\n\n<!-- from: package/server-variant -->\nvariant rule\n\n<!-- from: package/other -->\nother rule",
            $merged,
        );
    }

    public function test_a_targeted_replace_marker_appends_when_the_target_contributed_nothing(): void {
        $merged = new Merger()->merge([
            $this->source('general', "# T\n\n## A\n\nold rule\n"),
            $this->source('package/variant', "## A\n\n<!-- replace: package/absent -->\nvariant rule\n"),
        ])->markdown;

        self::assertSame("# T\n\n## A\n\nold rule\n\n<!-- from: package/variant -->\nvariant rule", $merged);
    }

    public function test_headings_inside_code_fences_are_not_sections(): void {
        $merged = new Merger()->merge([
            $this->source('general', "# T\n\n## A\n\n```md\n## Not a heading\n```\n"),
            $this->source('framework/laravel', "## Not a heading\n\nreal section\n"),
        ])->markdown;

        self::assertSame(['A', 'Not a heading'], $this->headings($merged));
        self::assertStringContainsString("```md\n## Not a heading\n```", $merged);
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    #[DataProvider('metadata')]
    public function test_frontmatter_is_merged(array $first, array $second, string $title, int $order, bool $agents): void {
        $merged = new Merger()->merge([$this->source('general', "# T\n", $first), $this->source('framework/laravel', "# T\n", $second)]);

        self::assertSame($title, $merged->title);
        self::assertSame($order, $merged->order);
        self::assertSame($agents, $merged->agents);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, string, int, bool}> */
    public static function metadata(): iterable {
        yield 'first layer wins title and order' => [['title' => 'A', 'order' => 5], ['title' => 'B', 'order' => 1], 'A', 5, false];
        yield 'agents is OR-ed' => [['title' => 'A'], ['agents' => true], 'A', 1000, true];
    }

    /** @return list<string> */
    private function headings(string $markdown): array {
        return array_map(static fn ($s): string => $s->heading, Document::parse($markdown)->sections);
    }
}
