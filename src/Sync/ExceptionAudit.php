<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\Config\RuleException;
use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Markdown\Anchors;

/** Validates the recorded project exceptions against the selected layers and the project's ADR files. */
final readonly class ExceptionAudit
{
    public function __construct(
        private LayerRepository $layers,
        private string $root,
    ) {
    }

    /**
     * @return list<string> one message per problem; empty when every exception is valid
     */
    public function problems(ProjectConfig $config): array {
        $problems = [];
        foreach ($config->exceptions as $exception) {
            $problems = [...$problems, ...$this->problemsOf($config, $exception)];
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function problemsOf(ProjectConfig $config, RuleException $exception): array {
        $prefix = sprintf('Exception "%s": ', $exception->rule);

        if ($exception->isProtected()) {
            return [$prefix . 'refused. The security and production-data rules (every `' . RuleException::PROTECTED_FILE . '.md`) cannot be excepted. Remove the entry from ' . ProjectConfig::FILE . '.'];
        }

        $problems = [];
        $layer = substr($exception->file, 0, (int) strrpos($exception->file, '/'));
        if ( ! in_array($layer, $config->layers, true)) {
            $problems[] = $prefix . sprintf('layer "%s" is not selected in %s, so the rule does not apply. Remove the exception or the layer.', $layer, ProjectConfig::FILE);
        } elseif (in_array($exception->name, $config->exclude, true)) {
            $problems[] = $prefix . sprintf('"%s.md" is excluded in %s, so the rule is not rendered. Remove the exception.', $exception->name, ProjectConfig::FILE);
        } else {
            $problems = [...$problems, ...$this->anchorProblems($layer, $exception, $prefix)];
        }

        if ( ! is_file($this->root . '/' . $exception->adr)) {
            $problems[] = $prefix . sprintf('ADR file "%s" does not exist. Record the exception in an accepted ADR and fix the path.', $exception->adr);
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function anchorProblems(string $layer, RuleException $exception, string $prefix): array {
        try {
            $files = $this->layers->files($layer);
        } catch (GuidelinesException $e) {
            return [$prefix . $e->getMessage()];
        }

        foreach ($files as $file) {
            if ($file->relativePath !== $exception->file) {
                continue;
            }
            if (Anchors::has($file->body, $exception->anchor)) {
                return [];
            }
            $anchors = array_map(static fn (array $heading): string => $heading['anchor'], Anchors::headings($file->body));

            return [$prefix . sprintf('%s has no heading "#%s" any more (renamed or removed in the installed guidelines). Anchors there: %s.', $exception->file, $exception->anchor, implode(', ', $anchors))];
        }

        return [$prefix . sprintf('the installed guidelines have no file "%s" (renamed or removed).', $exception->file)];
    }
}
