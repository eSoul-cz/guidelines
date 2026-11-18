<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Layer\SourceFile;
use Esoul\Guidelines\Merge\MergedGuideline;
use Esoul\Guidelines\Merge\Merger;

/** Renders the selected layers into the list of managed-block targets. */
final class Renderer
{
    public const string OUTPUT_DIR = 'docs/project_guidelines';

    public const string AGENTS_FILE = 'AGENTS.md';

    private const string LOCAL_NOTE = '> Local additions go below this managed block. Content outside the block is preserved when the guidelines are synced.';

    public function __construct(
        private readonly LayerRepository $layers,
        private readonly Merger $merger = new Merger(),
    ) {
    }

    /**
     * @return list<Target> guideline files (ordered), then the index, then the AGENTS.md block
     */
    public function render(ProjectConfig $config): array
    {
        $byName = [];
        foreach ($config->layers as $layer) {
            if (!$this->layers->has($layer)) {
                throw new GuidelinesException(sprintf('Unknown guideline layer "%s". Available: %s.', $layer, implode(', ', $this->layers->layers())));
            }
            foreach ($this->layers->files($layer) as $file) {
                if (!in_array($file->name, $config->exclude, true)) {
                    $byName[$file->name][] = $file;
                }
            }
        }

        $guidelines = [];
        foreach ($byName as $sources) {
            /** @var list<SourceFile> $sources */
            $guidelines[] = $this->merger->merge($sources);
        }
        usort($guidelines, static fn (MergedGuideline $a, MergedGuideline $b): int => [$a->order, $a->name] <=> [$b->order, $b->name]);

        $targets = [];
        foreach ($guidelines as $guideline) {
            $targets[] = new Target(
                Target::KIND_GUIDELINE,
                self::OUTPUT_DIR . '/' . $guideline->name . '.md',
                $guideline->name,
                $guideline->markdown . "\n\n" . self::LOCAL_NOTE,
                $guideline->sources,
            );
        }
        $targets[] = new Target(
            Target::KIND_INDEX,
            self::OUTPUT_DIR . '/index.md',
            'index',
            $this->renderIndex($config, $guidelines),
        );
        $targets[] = new Target(
            Target::KIND_AGENTS,
            self::AGENTS_FILE,
            'agents',
            $this->renderAgents($guidelines),
        );

        return $targets;
    }

    /**
     * @param list<MergedGuideline> $guidelines
     */
    private function renderIndex(ProjectConfig $config, array $guidelines): string
    {
        $lines = [
            '# Project guidelines - Index',
            '',
            'Read the guidelines that cover the code you are about to change. These rules take precedence over generic framework documentation and generated advice.',
            '',
            'Layers: ' . implode(', ', array_map(static fn (string $l): string => '`' . $l . '`', $config->layers)) . '.',
            '',
            '| Guideline | Covers | Layers |',
            '|---|---|---|',
        ];
        foreach ($guidelines as $guideline) {
            $lines[] = sprintf(
                '| [%s](%s.md) | %s | %s |',
                $guideline->title,
                $guideline->name,
                str_replace('|', '\\|', $guideline->summary),
                implode(', ', array_map(static fn (string $l): string => '`' . $l . '`', $guideline->layers)),
            );
        }
        $lines[] = '';
        $lines[] = self::LOCAL_NOTE;

        return implode("\n", $lines);
    }

    /**
     * @param list<MergedGuideline> $guidelines
     */
    private function renderAgents(array $guidelines): string
    {
        $lines = [
            '## Project guidelines',
            '',
            'Before changing code, read [docs/project_guidelines/index.md](' . self::OUTPUT_DIR . '/index.md) and the guidelines that cover what you touch. '
            . 'They take precedence over generic framework documentation and generated advice. '
            . 'This block is managed by `esoul-guidelines`; do not edit it, run `vendor/bin/esoul-guidelines sync` instead.',
        ];

        $always = array_filter($guidelines, static fn (MergedGuideline $g): bool => $g->agents);
        if ($always !== []) {
            $lines[] = '';
            $lines[] = 'Rules for every task:';
            $lines[] = '';
            foreach ($always as $guideline) {
                $lines[] = sprintf(
                    '- [%s](%s/%s.md)%s',
                    $guideline->title,
                    self::OUTPUT_DIR,
                    $guideline->name,
                    $guideline->summary !== '' ? ' - ' . $guideline->summary : '',
                );
            }
        }

        return implode("\n", $lines);
    }
}
