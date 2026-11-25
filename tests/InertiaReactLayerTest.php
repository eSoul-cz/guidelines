<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Markdown\Document;
use Esoul\Guidelines\Merge\Merger;
use PHPUnit\Framework\TestCase;

/** The bundled `package/laravel-inertia-react` layer against the layers whose Vue wording it replaces. */
final class InertiaReactLayerTest extends TestCase
{
    private const string REACT = 'package/laravel-inertia-react';

    /** Layers a React project selects with the React layer, whose text must not name Vue in its output. */
    private const array BASES = ['framework/laravel', 'package/laravel-inertia'];

    private const string INTRO = '(intro)';

    /** The only differences a React replacement may have from the text it replaces (besides additions around it). */
    private const array VUE_TO_REACT = [
        'Inertia 3, Vue 3' => 'Inertia 3 (`@inertiajs/react`), React 19',
        'Inertia with Vue' => 'Inertia (`@inertiajs/react`) with React',
        'shadcn-vue' => 'shadcn/ui',
        'Vue' => 'React',
    ];

    /**
     * A part the React layer replaces keeps the replaced layer's current text with only Vue named as React, so a later
     * change to that text that is not carried over to the React layer fails here.
     */
    public function test_every_replacement_carries_the_current_text_it_replaces_with_vue_named_as_react(): void {
        $replaced = 0;
        foreach ($this->parts(self::REACT) as $name => $parts) {
            foreach ($parts as $key => $body) {
                $targets = $this->targets($body);
                foreach ($targets as $target) {
                    ++$replaced;
                    $base = $this->parts($target)[$name][$key] ?? null;
                    self::assertNotNull($base, sprintf('%s.md › %s replaces %s, which has no such part.', $name, $key, $target));
                    self::assertStringContainsString(strtr($base, self::VUE_TO_REACT), $body, sprintf('%s.md › %s no longer matches %s.', $name, $key, $target));
                }
            }
        }
        self::assertGreaterThan(0, $replaced);
    }

    public function test_every_part_of_the_base_layers_that_names_vue_is_replaced(): void {
        $react = $this->parts(self::REACT);
        foreach (self::BASES as $layer) {
            foreach ($this->parts($layer) as $name => $parts) {
                foreach ($parts as $key => $body) {
                    if (str_contains($body, 'Vue')) {
                        self::assertContains($layer, $this->targets($react[$name][$key] ?? ''), sprintf('%s/%s.md › %s names Vue but the React layer does not replace it.', $layer, $name, $key));
                    }
                }
            }
        }
    }

    public function test_a_react_project_renders_react_and_a_vue_project_renders_vue(): void {
        $react = $this->render(['framework/laravel', 'package/laravel-inertia', self::REACT]);
        $vue = $this->render(['framework/laravel', 'package/inertia-vue', 'package/laravel-inertia']);

        foreach (['architecture', 'frontend', 'tools'] as $name) {
            self::assertStringNotContainsString('Vue', str_replace('Vue is not used next to it.', '', $react[$name]), $name);
            self::assertStringNotContainsString(self::REACT, $vue[$name], $name);
        }
        self::assertStringContainsString('Inertia (`@inertiajs/react`) with React', $react['frontend']);
        self::assertStringContainsString('React 19', $react['tools']);
        self::assertStringContainsString('shadcn/ui', $react['tools']);
        self::assertStringContainsString('the Vue sections of this file', $vue['frontend']);
        self::assertStringContainsString('Vue 3', $vue['tools']);
    }

    /** @return list<string> layers named by the replace marker at the start of `$body` */
    private function targets(string $body): array {
        if (preg_match(Document::REPLACE_PATTERN, $body, $match) !== 1 || ($match[1] ?? '') === '') {
            return [];
        }

        $targets = preg_split('/\s*,\s*/', $match[1], -1, PREG_SPLIT_NO_EMPTY);

        return $targets === false ? [] : $targets;
    }

    /** @return array<string, array<string, string>> file name => (section key or intro => trimmed text) */
    private function parts(string $layer): array {
        $parts = [];
        foreach (LayerRepository::bundled()->files($layer) as $file) {
            $document = Document::parse($file->body);
            $parts[$file->name] = [self::INTRO => trim($document->intro)];
            foreach ($document->sections as $section) {
                $parts[$file->name][$section->key] = trim($section->body);
            }
        }

        return $parts;
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
