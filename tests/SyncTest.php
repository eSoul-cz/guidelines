<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Sync\ManagedBlock;

final class SyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLayers();
        $this->config('{"layers": ["general", "framework/laravel"]}');
    }

    public function testSyncWritesMergedFilesIndexAgentsBlockLockAndClaudeFile(): void
    {
        file_put_contents($this->project . '/AGENTS.md', "# Agents\n\n<laravel-boost-guidelines>\nboost\n</laravel-boost-guidelines>\n");

        self::assertSame(0, $this->run_('sync')->getStatusCode());

        $testing = $this->read('docs/project_guidelines/testing.md');
        self::assertStringContainsString('General rule.', $testing);
        self::assertStringContainsString('Laravel rule.', $testing);
        self::assertStringContainsString('## Database in tests', $testing);
        self::assertStringContainsString('Local additions go below', $testing);
        self::assertStringContainsString('[Tools](tools.md)', $this->read('docs/project_guidelines/index.md'));

        $agents = $this->read('AGENTS.md');
        self::assertStringContainsString("<laravel-boost-guidelines>\nboost\n</laravel-boost-guidelines>", $agents);
        self::assertStringContainsString('docs/project_guidelines/index.md', $agents);
        self::assertStringContainsString('[Testing](docs/project_guidelines/testing.md)', $agents);
        self::assertStringNotContainsString('[Tools](docs/project_guidelines/tools.md)', $agents);

        self::assertSame("@AGENTS.md\n", $this->read('CLAUDE.md'));

        $lock = Lock::load($this->project);
        self::assertNotNull($lock);
        self::assertSame(['general', 'framework/laravel'], $lock->layers);
        $entry = $lock->files['docs/project_guidelines/testing.md'];
        self::assertSame(['general/testing.md', 'framework/laravel/testing.md'], array_keys($entry->sources));
        self::assertSame(ManagedBlock::hash($this->blockOf('docs/project_guidelines/testing.md', 'testing')), $entry->sha256);
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function testSyncCreatesAgentsFileAndKeepsAnExistingClaudeFile(): void
    {
        file_put_contents($this->project . '/CLAUDE.md', 'custom');

        $this->run_('sync');

        self::assertStringContainsString('esoul-guidelines:begin agents', $this->read('AGENTS.md'));
        self::assertSame('custom', $this->read('CLAUDE.md'));
    }

    public function testContentOutsideTheBlockSurvivesAResync(): void
    {
        $this->run_('sync');
        $path = $this->project . '/docs/project_guidelines/tools.md';
        file_put_contents($path, "Intro above.\n\n" . ltrim((string) file_get_contents($path)) . "\n## Local section\n\nOurs.\n");
        $this->layer('framework/laravel', 'tools.md', "---\ntitle: Tools\norder: 20\n---\n# Project guidelines - Tools\n\n## Versions\n\nPHP 8.6.\n");

        self::assertSame(0, $this->run_('sync')->getStatusCode());

        $tools = $this->read('docs/project_guidelines/tools.md');
        self::assertStringContainsString('PHP 8.6.', $tools);
        self::assertStringStartsWith("Intro above.\n\n<!-- esoul-guidelines:begin tools -->", $tools);
        self::assertStringEndsWith("<!-- esoul-guidelines:end tools -->\n\n## Local section\n\nOurs.\n", $tools);
    }

    public function testSyncRefusesLocallyEditedBlockAndForceOverwritesIt(): void
    {
        $this->run_('sync');
        $path = $this->project . '/docs/project_guidelines/testing.md';
        file_put_contents($path, str_replace('General rule.', 'Edited rule.', (string) file_get_contents($path)));
        $before = (string) file_get_contents($path);

        $refused = $this->run_('sync');

        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('docs/project_guidelines/testing.md', $refused->getDisplay());
        self::assertSame($before, (string) file_get_contents($path), 'nothing is written on refusal');

        $forced = $this->run_('sync', ['--force' => true]);

        self::assertSame(0, $forced->getStatusCode());
        self::assertStringContainsString('General rule.', $this->read('docs/project_guidelines/testing.md'));
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function testDryRunWritesNothingAndReportsChanges(): void
    {
        $tester = $this->run_('sync', ['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('create', $tester->getDisplay());
        self::assertFileDoesNotExist($this->project . '/docs/project_guidelines/testing.md');
        self::assertFileDoesNotExist($this->project . '/' . Lock::FILE);
    }

    public function testCheckReportsDriftWhenABlockWasEdited(): void
    {
        $this->run_('sync');
        $path = $this->project . '/docs/project_guidelines/tools.md';
        file_put_contents($path, str_replace('PHP 8.5.', 'PHP 7.', (string) file_get_contents($path)));

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('Drift', $check->getDisplay());
        self::assertStringNotContainsString('Outdated', $check->getDisplay());
    }

    public function testCheckReportsOutdatedWhenTheSourcesChanged(): void
    {
        $this->run_('sync');
        $this->layer('framework/laravel', 'tools.md', "---\ntitle: Tools\norder: 20\n---\n# Project guidelines - Tools\n\n## Versions\n\nPHP 8.6.\n");

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('Outdated', $check->getDisplay());
        self::assertStringContainsString('tools.md', $check->getDisplay());
        self::assertStringNotContainsString('Drift', $check->getDisplay());
    }

    public function testCheckFailsWithoutLock(): void
    {
        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString(Lock::FILE, $check->getDisplay());
    }

    public function testRemovingALayerRemovesItsContributionAndOrphanedBlocks(): void
    {
        $this->run_('sync');
        $tools = $this->project . '/docs/project_guidelines/tools.md';
        $testing = $this->project . '/docs/project_guidelines/testing.md';
        file_put_contents($testing, (string) file_get_contents($testing) . "\nMy own testing notes.\n");
        $this->config('{"layers": ["general"]}');

        self::assertSame(1, $this->run_('check')->getStatusCode(), 'config differs from the lock');
        self::assertSame(0, $this->run_('sync')->getStatusCode());

        $merged = $this->read('docs/project_guidelines/testing.md');
        self::assertStringNotContainsString('Laravel rule.', $merged);
        self::assertStringNotContainsString('Database in tests', $merged);
        self::assertStringContainsString('General rule.', $merged);
        self::assertStringContainsString('My own testing notes.', $merged);
        self::assertFileDoesNotExist($tools, 'orphaned file without local content is deleted');
        self::assertArrayNotHasKey('docs/project_guidelines/tools.md', Lock::load($this->project)->files);
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function testOrphanedFileKeepsLocalContentButLosesItsBlock(): void
    {
        $this->run_('sync');
        $tools = $this->project . '/docs/project_guidelines/tools.md';
        file_put_contents($tools, (string) file_get_contents($tools) . "\nKeep me.\n");
        $this->config('{"layers": ["general"]}');

        self::assertSame(0, $this->run_('sync')->getStatusCode());

        self::assertSame("Keep me.\n", $this->read('docs/project_guidelines/tools.md'));
    }

    public function testExcludedFilesAreNotProduced(): void
    {
        $this->config('{"layers": ["general", "framework/laravel"], "exclude": ["tools"]}');

        $this->run_('sync');

        self::assertFileDoesNotExist($this->project . '/docs/project_guidelines/tools.md');
        self::assertStringNotContainsString('tools.md', $this->read('docs/project_guidelines/index.md'));
    }

    public function testUnknownLayerFailsWithAClearMessage(): void
    {
        $this->config('{"layers": ["general", "framework/nope"]}');

        $tester = $this->run_('sync');

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('framework/nope', $tester->getDisplay());
    }

    private function blockOf(string $relative, string $name): string
    {
        return ManagedBlock::find($this->read($relative), $name)['content'] ?? '';
    }
}
