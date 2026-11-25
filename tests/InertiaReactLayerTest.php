<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Layer\SourceFile;
use Esoul\Guidelines\Markdown\Document;
use Esoul\Guidelines\Merge\Merger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The bundled `package/laravel-inertia-react` layer against the `package/laravel-inertia` layer it adapts to React. */
final class InertiaReactLayerTest extends TestCase
{
    private const string BASE = 'package/laravel-inertia';

    private const string REACT = 'package/laravel-inertia-react';

    private const string MARKER = '<!-- replace: ' . self::BASE . ' -->';

    /** The only differences a React section may have from the Vue text it replaces (besides additions around it). */
    private const array VUE_TO_REACT = [
        'Inertia 3, Vue 3' => 'Inertia 3 (`@inertiajs/react`), React 19',
        'Inertia with Vue' => 'Inertia (`@inertiajs/react`) with React',
        'shadcn-vue' => 'shadcn/ui',
        'Vue' => 'React',
    ];

    /** @return iterable<string, array{string}> */
    public static function files(): iterable {
        foreach (LayerRepository::bundled()->files(self::REACT) as $file) {
            yield $file->name => [$file->name];
        }
    }

    /**
     * A section the React layer replaces keeps the base layer's text with only Vue named as React, so a later change to
     * the base section that is not carried over to the React layer fails here.
     */
    #[DataProvider('files')]
    public function test_every_replaced_section_carries_the_current_base_text_with_vue_named_as_react(string $name): void {
        $base = $this->sections($this->file(self::BASE, $name));
        $replaced = 0;
        foreach ($this->sections($this->file(self::REACT, $name)) as $key => $body) {
            if ( ! str_starts_with($body, self::MARKER)) {
                continue;
            }
            ++$replaced;
            self::assertArrayHasKey($key, $base, sprintf('%s.md replaces "%s", which %s does not have.', $name, $key, self::BASE));
            self::assertStringContainsString(strtr($base[$key], self::VUE_TO_REACT), $body, sprintf('%s.md › %s no longer matches %s.', $name, $key, self::BASE));
        }

        foreach ($base as $key => $body) {
            if (str_contains($body, 'Vue')) {
                self::assertStringStartsWith(self::MARKER, $this->sections($this->file(self::REACT, $name))[$key] ?? '', sprintf('%s.md › %s names Vue but the React layer does not replace it.', $name, $key));
            }
        }
        self::assertGreaterThan(0, $replaced);
    }

    public function test_a_react_project_renders_react_and_a_vue_project_renders_vue(): void {
        $react = $this->render(['framework/laravel', self::BASE, self::REACT]);
        $vue = $this->render(['framework/laravel', 'package/inertia-vue', self::BASE]);

        foreach (['frontend', 'tools'] as $name) {
            self::assertStringNotContainsString('shadcn-vue', $react[$name]);
            self::assertStringNotContainsString('Vue 3', $react[$name]);
            self::assertStringNotContainsString('Vue sections', $react[$name]);
            self::assertStringNotContainsString(self::REACT, $vue[$name]);
        }
        self::assertStringContainsString('Inertia (`@inertiajs/react`) with React', $react['frontend']);
        self::assertStringContainsString('React 19', $react['tools']);
        self::assertStringContainsString('shadcn/ui', $react['tools']);
        self::assertStringContainsString('the Vue sections of this file', $vue['frontend']);
        self::assertStringContainsString('Vue 3', $vue['tools']);
    }

    private function file(string $layer, string $name): SourceFile {
        foreach (LayerRepository::bundled()->files($layer) as $file) {
            if ($file->name === $name) {
                return $file;
            }
        }
        self::fail(sprintf('%s has no %s.md.', $layer, $name));
    }

    /** @return array<string, string> section key => trimmed body */
    private function sections(SourceFile $file): array {
        $sections = [];
        foreach (Document::parse($file->body)->sections as $section) {
            $sections[$section->key] = trim($section->body);
        }

        return $sections;
    }

    /**
     * @param list<string> $layers
     * @return array<string, string> file name => merged Markdown
     */
    private function render(array $layers): array {
        $repository = LayerRepository::bundled();
        $byName = [];
        foreach (['general', ...$layers] as $layer) {
            foreach ($repository->files($layer) as $file) {
                $byName[$file->name][] = $file;
            }
        }

        return array_map(static fn (array $sources): string => new Merger()->merge($sources)->markdown, $byName);
    }
}
