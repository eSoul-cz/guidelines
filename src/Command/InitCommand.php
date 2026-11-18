<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\Detect\StackDetector;
use Esoul\Guidelines\Layer\LayerRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'init', description: 'Detect the project stack and write esoul-guidelines.json')]
final class InitCommand extends ProjectCommand
{
    protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int
    {
        $existing = ProjectConfig::exists($root) ? ProjectConfig::load($root) : null;
        $detected = (new StackDetector())->detect($root);

        $unavailable = array_values(array_filter($detected, fn (string $l): bool => !$this->layers->has($l)));
        $detected = array_values(array_filter($detected, fn (string $l): bool => $this->layers->has($l)));
        if ($unavailable !== []) {
            $io->note('Detected, but no guideline layer exists yet: ' . implode(', ', $unavailable));
        }

        $default = $existing !== null
            ? array_values(array_filter($existing->layers, static fn (string $l): bool => $l !== LayerRepository::GENERAL))
            : $detected;
        $selected = $default;

        if ($input->isInteractive()) {
            $choices = array_values(array_filter($this->layers->layers(), static fn (string $l): bool => $l !== LayerRepository::GENERAL));
            $defaultIndexes = array_keys(array_filter($choices, static fn (string $l): bool => in_array($l, $default, true)));
            $question = new ChoiceQuestion(
                'Which guideline layers apply? (`general` is always included; comma-separated numbers)',
                $choices,
                $defaultIndexes === [] ? null : implode(',', $defaultIndexes),
            );
            $question->setMultiselect(true);
            /** @var list<string> $selected */
            $selected = (new QuestionHelper())->ask($input, $io, $question);
            // Keep the order of the available layers, not the order in which numbers were typed.
            $selected = array_values(array_filter($choices, static fn (string $l): bool => in_array($l, $selected, true)));
        }

        $config = ProjectConfig::create($selected, $existing->exclude ?? []);
        $config->save($root);

        $io->success(sprintf('Wrote %s with layers: %s.', ProjectConfig::FILE, implode(', ', $config->layers)));
        $io->writeln('Next: `esoul-guidelines sync`, then commit the generated docs, ' . ProjectConfig::FILE . ' and the lock file.');

        return self::SUCCESS;
    }
}
