<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Application;
use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Source\GitRunner;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

abstract class TestCase extends BaseTestCase
{
    protected string $tmp;

    protected string $layersDir;

    protected string $project;

    /** Replaces the `git` CLI in the application under test; null runs the real one. */
    protected ?GitRunner $git = null;

    /** The installer version the application under test reports; a non-release (`test`) satisfies any source constraint. */
    protected string $installerVersion = 'test';

    protected function setUp(): void {
        $this->tmp = sys_get_temp_dir() . '/esoul-guidelines-test-' . bin2hex(random_bytes(6));
        $this->layersDir = $this->tmp . '/layers';
        $this->project = $this->tmp . '/project';
        mkdir($this->layersDir, 0777, true);
        mkdir($this->project, 0777, true);
    }

    protected function tearDown(): void {
        new Filesystem()->remove($this->tmp);
    }

    protected function layer(string $layer, string $file, string $content): void {
        $dir = $this->layersDir . '/' . $layer;
        if ( ! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $file, $content);
    }

    /** Two small layers sharing `testing.md`; the framework layer also ships `tools.md`. */
    protected function seedLayers(): void {
        $this->layer('general', 'testing.md', "---\ntitle: Testing\norder: 10\nsummary: How we test.\nagents: true\n---\n# Project guidelines - Testing\n\nGeneral intro.\n\n## Writing tests\n\nGeneral rule.\n\n## Flaky tests\n\nFix them.\n");
        $this->layer('framework/laravel', 'testing.md', "---\ntitle: Testing\n---\n# Project guidelines - Testing\n\nLaravel intro.\n\n## writing tests\n\nLaravel rule.\n\n## Database in tests\n\nUse sqlite.\n");
        $this->layer('framework/laravel', 'tools.md', "---\ntitle: Tools\norder: 20\n---\n# Project guidelines - Tools\n\n## Versions\n\nPHP 8.5.\n");
    }

    protected function config(string $json): void {
        file_put_contents($this->project . '/esoul-guidelines.json', $json);
    }

    /** @return array<mixed> the decoded JSON file of the project */
    protected function json(string $relative): array {
        $data = json_decode($this->read($relative), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** Asserts that `$action` throws a {@see GuidelinesException} whose message contains `$message`. */
    protected function assertFailsWith(string $message, callable $action): void {
        try {
            $action();
        } catch (GuidelinesException $e) {
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }

        self::fail('Expected a GuidelinesException containing: ' . $message);
    }

    protected function read(string $relative): string {
        return (string) file_get_contents($this->project . '/' . $relative);
    }

    /**
     * Runs a command interactively with the given answers (one per question; an empty string accepts the default).
     *
     * @param list<string> $answers
     * @param array<string, mixed> $input arguments and options
     */
    protected function runInteractive(string $command, array $answers, array $input = []): CommandTester {
        $app = new Application(new LayerRepository($this->layersDir), $this->installerVersion, $this->git);
        $tester = new CommandTester($app->find($command));
        $tester->setInputs($answers);
        $tester->execute($input + ['--working-dir' => $this->project], ['interactive' => true]);

        return $tester;
    }

    /** @param array<string, mixed> $input */
    protected function run_(string $command, array $input = []): CommandTester {
        $app = new Application(new LayerRepository($this->layersDir), $this->installerVersion, $this->git);
        $tester = new CommandTester($app->find($command));
        $tester->execute($input + ['--working-dir' => $this->project], ['interactive' => false]);

        return $tester;
    }
}
