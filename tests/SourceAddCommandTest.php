<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Source\CopyTree;

final class SourceAddCommandTest extends SourceTestCase
{
    private string $repo;

    protected function setUp(): void {
        parent::setUp();
        $this->repo = $this->sourceRepo();
        $this->config('{"layers": ["general", "framework/laravel"]}');
    }

    public function test_non_interactive_adds_the_source_without_layers_prints_them_and_syncs(): void {
        $tester = $this->run_('source:add', ['url' => $this->repo]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(
            ['sources' => ['acme' => ['url' => $this->repo, 'ref' => 'main']], 'layers' => ['general', 'framework/laravel']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
        self::assertStringContainsString('acme:package/billing', $tester->getDisplay());
        self::assertStringContainsString('acme:package/sso', $tester->getDisplay());
        self::assertSame(['source.json'], CopyTree::files($this->project . '/.esoul/guidelines/acme'));
        self::assertSame(trim($this->gitIn($this->repo, ['rev-parse', 'HEAD'])), $this->lock()->sources['acme']->commit);
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_interactive_offers_the_layers_and_renders_the_selected_one(): void {
        // Entries: 0 none, 1 acme:package/billing, 2 acme:package/sso.
        $tester = $this->runInteractive('source:add', ['2'], ['url' => $this->repo]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Rules of acme', $tester->getDisplay());
        self::assertSame(['general', 'framework/laravel', 'acme:package/sso'], $this->json('esoul-guidelines.json')['layers']);
        self::assertSame(['package/sso/auth.md', 'package/sso/testing.md', 'source.json'], CopyTree::files($this->project . '/.esoul/guidelines/acme'));
        self::assertStringContainsString('Tokens expire after 5 minutes.', $this->read('docs/project_guidelines/auth.md'));
        self::assertStringContainsString('<!-- from: acme:package/sso -->', $this->read('docs/project_guidelines/testing.md'));
    }

    public function test_ref_option_selects_a_tag(): void {
        $first = trim($this->gitIn($this->repo, ['rev-parse', 'HEAD']));
        $this->gitIn($this->repo, ['tag', 'v1']);
        $this->commitSource($this->repo, ['CHANGELOG.md' => "# Changelog\n\nNext.\n"], 'Next');

        $tester = $this->run_('source:add', ['url' => $this->repo, '--ref' => 'v1']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['acme' => ['url' => $this->repo, 'ref' => 'v1']], $this->json('esoul-guidelines.json')['sources']);
        self::assertSame($first, $this->lock()->sources['acme']->commit);
    }

    public function test_adding_a_second_source_with_the_same_name_is_an_error_and_changes_nothing(): void {
        $this->run_('source:add', ['url' => $this->repo]);
        $config = $this->read('esoul-guidelines.json');
        $twin = $this->sourceRepo('twin', $this->defaultSourceFiles('acme'));

        $tester = $this->run_('source:add', ['url' => $twin]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('A source named "acme" is already configured', $this->flat($tester->getDisplay()));
        self::assertSame($config, $this->read('esoul-guidelines.json'));
    }

    public function test_an_incompatible_source_is_refused_before_the_config_changes(): void {
        $strict = $this->sourceRepo('strict', $this->defaultSourceFiles('strict', '^9.0'));
        $this->installerVersion = '1.0.0';
        $before = $this->read('esoul-guidelines.json');

        $tester = $this->run_('source:add', ['url' => $strict]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('needs esoul-guidelines "^9.0"', $this->flat($tester->getDisplay()));
        self::assertSame($before, $this->read('esoul-guidelines.json'));
    }

    public function test_a_repository_without_source_json_and_an_unreachable_url_are_errors(): void {
        $plain = $this->tmp . '/sources/plain';
        mkdir($plain, 0777, true);
        $this->gitIn($plain, ['init', '--quiet', '-b', 'main']);
        $this->commitSource($plain, ['README.md' => "x\n"], 'Initial');

        $missing = $this->run_('source:add', ['url' => $plain]);
        self::assertSame(1, $missing->getStatusCode());
        self::assertStringContainsString('has no guidelines/source.json', $this->flat($missing->getDisplay()));

        $unreachable = $this->run_('source:add', ['url' => $this->tmp . '/nowhere']);
        self::assertSame(1, $unreachable->getStatusCode());
        self::assertStringContainsString('fatal', $this->flat($unreachable->getDisplay()));
        self::assertStringNotContainsString('sources', $this->read('esoul-guidelines.json'));
    }

    public function test_the_command_needs_a_config(): void {
        unlink($this->project . '/esoul-guidelines.json');

        $tester = $this->run_('source:add', ['url' => $this->repo]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('esoul-guidelines init', $this->flat($tester->getDisplay()));
    }
}
