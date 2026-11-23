<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

/** A repository without PHP (only `package.json`) must work with every command: no `composer.json`, no `vendor/`. */
final class WithoutComposerTest extends TestCase
{
    public function test_init_sync_and_check_work_in_a_project_with_only_a_package_json(): void {
        $this->seedLayers();
        $this->layer('framework/astro', 'tools.md', "---\ntitle: Tools\n---\n# Project guidelines - Tools\n\n## Versions\n\nNode 24.\n");
        file_put_contents($this->project . '/package.json', '{"dependencies":{"astro":"^5.0"}}');

        self::assertSame(0, $this->run_('init')->getStatusCode());
        self::assertSame(['layers' => ['general', 'framework/astro']], json_decode($this->read('esoul-guidelines.json'), true));

        self::assertSame(0, $this->run_('sync')->getStatusCode());
        self::assertSame(0, $this->run_('check')->getStatusCode());
        self::assertSame(0, $this->run_('list')->getStatusCode());

        self::assertStringContainsString('Node 24.', $this->read('docs/project_guidelines/tools.md'));
        self::assertStringContainsString('run `esoul-guidelines sync`', $this->read('AGENTS.md'));
        self::assertFileDoesNotExist($this->project . '/composer.json');
        self::assertDirectoryDoesNotExist($this->project . '/vendor');
    }
}
