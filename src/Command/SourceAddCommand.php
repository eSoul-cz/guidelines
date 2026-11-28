<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Command;

use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\Config\SourceSpec;
use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Source\SourceManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'source:add', description: 'Add a guideline source: read its source.json, offer its layers, then sync')]
final class SourceAddCommand extends SyncingCommand
{
    private const string NO_LAYER = 'none';

    protected function configure(): void {
        parent::configure();
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'Git URL (or path) of the source repository')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Branch or tag to follow', SourceSpec::DEFAULT_REF);
    }

    protected function handle(string $root, InputInterface $input, SymfonyStyle $io): int {
        $config = ProjectConfig::load($root);
        $url = $input->getArgument('url');
        $ref = $input->getOption('ref');
        if ( ! is_string($url) || ! is_string($ref)) {
            throw new GuidelinesException('Usage: esoul-guidelines source:add <url> [--ref=main]');
        }
        $probe = SourceSpec::fromArray('probe', ['url' => $url, 'ref' => $ref]); // validates the URL and the ref
        $url = $probe->url;
        $ref = $probe->ref;

        $checkout = $this->fetcher->fetch($url, $ref);
        try {
            $manifest = SourceManifest::read($checkout->guidelinesDir() . '/' . SourceManifest::FILE, $url);
            $manifest->assertCompatible($this->packageVersion);
            $layers = new LayerRepository($checkout->guidelinesDir(), $manifest->name)->layers();
        } finally {
            $checkout->cleanup();
        }

        $name = $manifest->name;
        if (isset($config->sources[$name])) {
            throw new GuidelinesException(sprintf('A source named "%s" is already configured in %s (%s). Two sources cannot have the same name.', $name, ProjectConfig::FILE, $config->sources[$name]->url));
        }

        $selected = [];
        if ($layers === []) {
            $io->note(sprintf('The source "%s" has no layers.', $name));
        } elseif ($input->isInteractive()) {
            $io->writeln(sprintf('Source <info>%s</info>: %s', $name, $manifest->description));
            $choices = [self::NO_LAYER, ...$layers];
            $question = new ChoiceQuestion(
                sprintf('Which layers of "%s" apply? (comma-separated numbers; `%s` selects none)', $name, self::NO_LAYER),
                $choices,
                '0',
            );
            $question->setMultiselect(true);
            /** @var list<string> $answer */
            $answer = new QuestionHelper()->ask($input, $io, $question);
            $selected = array_values(array_filter($layers, static fn (string $layer): bool => in_array($layer, $answer, true)));
        } else {
            $io->writeln(sprintf('Layers of <info>%s</info> (none added; list them under "layers" in %s):', $name, ProjectConfig::FILE));
            $io->listing($layers);
        }

        $updated = ProjectConfig::create([...$config->layers, ...$selected], $config->exclude, $config->exceptions, [...$config->sources, $name => new SourceSpec($name, $url, $ref)]);
        $updated->save($root);
        $io->success(sprintf('Added the source "%s" to %s%s.', $name, ProjectConfig::FILE, $selected === [] ? '' : ' with the layers ' . implode(', ', $selected)));

        return $this->synchronize($root, $io, false, false);
    }
}
