<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Source\CopyTree;
use Esoul\Guidelines\Source\GitRunner;
use Esoul\Guidelines\Source\ProcessGitRunner;
use Esoul\Guidelines\Source\SourceFetcher;
use Esoul\Guidelines\Sync\ManagedBlock;
use LogicException;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class SourcesTest extends SourceTestCase
{
    private const string COPY = '.esoul/guidelines/acme';

    private string $repo;

    protected function setUp(): void {
        parent::setUp();
        $this->repo = $this->sourceRepo();
        $this->configWithSource($this->repo);
    }

    public function test_sync_copies_exactly_the_selected_layers_and_renders_the_merged_output(): void {
        $tester = $this->run_('sync');

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['package/sso/auth.md', 'package/sso/testing.md', 'source.json'], CopyTree::files($this->project . '/' . self::COPY), 'the unselected layer package/billing and the CHANGELOG are not copied');
        self::assertSame(file_get_contents($this->repo . '/guidelines/package/sso/auth.md'), $this->read(self::COPY . '/package/sso/auth.md'));
        self::assertStringContainsString('Tokens expire after 5 minutes.', $this->read('docs/project_guidelines/auth.md'));
        self::assertStringContainsString('[Auth](auth.md)', $this->read('docs/project_guidelines/index.md'));
        self::assertStringContainsString('`acme:package/sso`', $this->read('docs/project_guidelines/index.md'));
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_a_source_layer_appends_to_and_replaces_sections_of_bundled_files_with_its_origin_marker(): void {
        $this->run_('sync');

        $testing = $this->read('docs/project_guidelines/testing.md');
        self::assertStringContainsString("<!-- from: acme:package/sso -->\nAcme replaces the writing rules.", $testing);
        self::assertStringNotContainsString('General rule.', $testing, 'replace swaps the earlier layers\' text');
        self::assertStringNotContainsString('Laravel rule.', $testing);
        self::assertStringContainsString("Fix them.\n\n<!-- from: acme:package/sso -->\nAcme adds a quarantine list.", $testing, 'a section without the marker is appended');
        self::assertStringContainsString('## Database in tests', $testing);
    }

    public function test_the_lock_is_schema_2_with_the_pin_the_file_hashes_and_prefixed_block_sources(): void {
        $this->run_('sync');
        $commit = trim($this->gitIn($this->repo, ['rev-parse', 'HEAD']));

        $raw = $this->json('esoul-guidelines.lock');
        self::assertSame(2, $raw['schema_version']);
        self::assertSame(['general', 'framework/laravel', 'acme:package/sso'], $raw['layers']);

        $source = $this->lock()->sources['acme'];
        self::assertSame($this->repo, $source->url);
        self::assertSame('main', $source->ref);
        self::assertSame($commit, $source->commit);
        self::assertSame(['package/sso/auth.md', 'package/sso/testing.md', 'source.json'], array_keys($source->files));
        self::assertSame(hash('sha256', $this->read(self::COPY . '/package/sso/auth.md')), $source->files['package/sso/auth.md']);

        $auth = $this->lock()->files['docs/project_guidelines/auth.md'];
        self::assertSame(['acme:package/sso/auth.md'], array_keys($auth->sources));
        self::assertSame(hash('sha256', $this->read(self::COPY . '/package/sso/auth.md')), $auth->sources['acme:package/sso/auth.md']);
        self::assertSame(['general/testing.md', 'framework/laravel/testing.md', 'acme:package/sso/testing.md'], array_keys($this->lock()->files['docs/project_guidelines/testing.md']->sources));
    }

    public function test_check_is_offline_and_needs_no_git_and_no_source_repository(): void {
        $this->run_('sync');
        new Filesystem()->remove($this->repo);
        $this->git = new class implements GitRunner {
            public function run(array $arguments, ?string $cwd = null): string {
                throw new LogicException('check must not run git');
            }
        };

        $check = $this->run_('check');

        self::assertSame(0, $check->getStatusCode(), $check->getDisplay());
    }

    public function test_check_fails_on_a_copied_file_edited_by_hand_and_names_it(): void {
        $this->run_('sync');
        $path = $this->project . '/' . self::COPY . '/package/sso/auth.md';
        file_put_contents($path, str_replace('5 minutes', '50 minutes', (string) file_get_contents($path)));

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString(self::COPY . '/package/sso/auth.md', $this->flat($check->getDisplay()));
        self::assertStringContainsString('edited by hand', $this->flat($check->getDisplay()));
    }

    public function test_check_fails_on_a_file_added_to_or_deleted_from_the_copy(): void {
        $this->run_('sync');
        file_put_contents($this->project . '/' . self::COPY . '/package/sso/extra.md', "# Extra\n");

        $added = $this->run_('check');
        self::assertSame(1, $added->getStatusCode());
        self::assertStringContainsString(self::COPY . '/package/sso/extra.md was added by hand', $this->flat($added->getDisplay()));

        unlink($this->project . '/' . self::COPY . '/package/sso/extra.md');
        unlink($this->project . '/' . self::COPY . '/package/sso/testing.md');
        $deleted = $this->run_('check');
        self::assertSame(1, $deleted->getStatusCode());
        self::assertStringContainsString(self::COPY . '/package/sso/testing.md is missing', $this->flat($deleted->getDisplay()));
    }

    public function test_check_fails_when_a_selected_source_layer_is_missing_from_the_copy(): void {
        $this->run_('sync');
        new Filesystem()->remove($this->project . '/' . self::COPY . '/package');

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('the selected layer "acme:package/sso" is missing from the copy', $this->flat($check->getDisplay()));
    }

    public function test_check_fails_when_a_configured_source_is_not_in_the_lock(): void {
        $this->run_('sync');
        $lock = $this->json('esoul-guidelines.lock');
        unset($lock['sources']);
        file_put_contents($this->project . '/esoul-guidelines.lock', json_encode($lock, JSON_THROW_ON_ERROR));

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('acme: the source is configured in esoul-guidelines.json but not in esoul-guidelines.lock', $this->flat($check->getDisplay()));
    }

    public function test_check_fails_when_the_config_points_the_source_elsewhere_than_the_lock(): void {
        $this->run_('sync');
        $this->configWithSource($this->repo, '"acme:package/sso"', 'develop');

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('url or ref in esoul-guidelines.json', $this->flat($check->getDisplay()));
    }

    public function test_check_reports_a_source_that_is_no_longer_configured(): void {
        $this->run_('sync');
        $this->config('{"layers": ["general", "framework/laravel"]}');

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('acme: the source is in esoul-guidelines.lock but no longer configured', $this->flat($check->getDisplay()));
    }

    public function test_a_second_sync_neither_fetches_nor_changes_a_file(): void {
        $this->run_('sync');
        $calls = $this->recorder->calls;
        self::assertGreaterThan(0, $calls);
        $this->ageFiles();
        $before = $this->snapshot();

        $second = $this->run_('sync');

        self::assertSame(0, $second->getStatusCode());
        self::assertStringContainsString('up to date', $second->getDisplay());
        self::assertSame($calls, $this->recorder->calls, 'no git command ran');
        self::assertSame($before, $this->snapshot(), 'no file was written');
    }

    public function test_a_selected_layer_the_copy_lacks_is_fetched_at_the_locked_commit_not_the_head(): void {
        $this->run_('sync');
        $locked = $this->lock()->sources['acme']->commit;
        $this->commitSource($this->repo, ['guidelines/package/billing/invoices.md' => "# Project guidelines - Invoices\n\n## Numbering\n\nNever gapless.\n"], 'Change billing');
        $this->configWithSource($this->repo, '"acme:package/sso", "acme:package/billing"');

        $sync = $this->run_('sync');

        self::assertSame(0, $sync->getStatusCode(), $sync->getDisplay());
        self::assertSame($locked, $this->lock()->sources['acme']->commit, 'the pin did not move');
        self::assertStringContainsString('Gapless.', $this->read(self::COPY . '/package/billing/invoices.md'));
        self::assertStringNotContainsString('Never', $this->read('docs/project_guidelines/invoices.md'));
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_deselecting_a_layer_removes_its_files_from_the_copy_without_fetching(): void {
        $this->configWithSource($this->repo, '"acme:package/sso", "acme:package/billing"');
        $this->run_('sync');
        self::assertFileExists($this->project . '/' . self::COPY . '/package/billing/invoices.md');
        $calls = $this->recorder->calls;
        $this->configWithSource($this->repo);

        $sync = $this->run_('sync');

        self::assertSame(0, $sync->getStatusCode(), $sync->getDisplay());
        self::assertSame($calls, $this->recorder->calls);
        self::assertSame(['package/sso/auth.md', 'package/sso/testing.md', 'source.json'], CopyTree::files($this->project . '/' . self::COPY));
        self::assertDirectoryDoesNotExist($this->project . '/' . self::COPY . '/package/billing');
        self::assertArrayNotHasKey('package/billing/invoices.md', $this->lock()->sources['acme']->files);
        self::assertFileDoesNotExist($this->project . '/docs/project_guidelines/invoices.md');
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_removing_a_source_from_the_config_removes_its_copy_and_lock_entry(): void {
        $this->run_('sync');
        $this->config('{"layers": ["general", "framework/laravel"]}');

        $sync = $this->run_('sync');

        self::assertSame(0, $sync->getStatusCode(), $sync->getDisplay());
        self::assertDirectoryDoesNotExist($this->project . '/.esoul');
        self::assertSame([], $this->lock()->sources);
        self::assertFileDoesNotExist($this->project . '/docs/project_guidelines/auth.md');
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_sync_refuses_a_copy_edited_by_hand_and_force_restores_it(): void {
        $this->run_('sync');
        $path = $this->project . '/' . self::COPY . '/package/sso/auth.md';
        $original = (string) file_get_contents($path);
        file_put_contents($path, $original . "\nHand edit.\n");

        $refused = $this->run_('sync');

        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString(self::COPY . '/package/sso/auth.md was edited by hand', $this->flat($refused->getDisplay()));
        self::assertSame($original . "\nHand edit.\n", file_get_contents($path), 'nothing is written on refusal');

        $forced = $this->run_('sync', ['--force' => true]);

        self::assertSame(0, $forced->getStatusCode(), $forced->getDisplay());
        self::assertSame($original, file_get_contents($path));
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_dry_run_fetches_to_a_temporary_directory_and_writes_nothing(): void {
        $dry = $this->run_('sync', ['--dry-run' => true]);

        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString('fetch', $dry->getDisplay());
        self::assertStringContainsString('auth.md', $dry->getDisplay());
        self::assertDirectoryDoesNotExist($this->project . '/.esoul');
        self::assertFileDoesNotExist($this->project . '/esoul-guidelines.lock');
        self::assertFileDoesNotExist($this->project . '/docs/project_guidelines/auth.md');
    }

    public function test_update_moves_the_pin_re_renders_and_prints_the_new_changelog_lines(): void {
        $this->run_('sync');
        $old = $this->lock()->sources['acme']->commit;
        $new = $this->commitSource($this->repo, [
            'guidelines/package/sso/auth.md' => "---\ntitle: Auth\nsummary: Single sign-on.\n---\n# Project guidelines - Auth\n\n## Tokens\n\nTokens expire after 10 minutes.\n",
            'CHANGELOG.md' => "# Changelog\n\n## [1.1.0]\n\n- Tokens expire after 10 minutes.\n\n## [1.0.0]\n\n- First release.\n",
        ], 'Longer tokens');

        $update = $this->run_('update', ['source' => ['acme']]);

        self::assertSame(0, $update->getStatusCode(), $update->getDisplay());
        self::assertNotSame($old, $new);
        self::assertSame($new, $this->lock()->sources['acme']->commit);
        self::assertStringContainsString('Tokens expire after 10 minutes.', $this->read('docs/project_guidelines/auth.md'));
        self::assertStringContainsString('Tokens expire after 10 minutes.', $this->read(self::COPY . '/package/sso/auth.md'));
        $display = $update->getDisplay();
        self::assertStringContainsString('Changelog of acme', $display);
        self::assertStringContainsString('## [1.1.0]', $display);
        self::assertStringContainsString('- Tokens expire after 10 minutes.', $display);
        self::assertStringNotContainsString('First release', $display, 'only the added lines are printed');
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_update_without_a_new_commit_changes_nothing(): void {
        $this->run_('sync');
        $this->ageFiles();
        $before = $this->snapshot();

        $update = $this->run_('update');

        self::assertSame(0, $update->getStatusCode(), $update->getDisplay());
        self::assertStringContainsString('acme is already at', $update->getDisplay());
        self::assertSame($before, $this->snapshot());
    }

    public function test_update_follows_a_tag_and_dry_run_writes_nothing(): void {
        $first = trim($this->gitIn($this->repo, ['rev-parse', 'HEAD']));
        $this->gitIn($this->repo, ['tag', 'v1']);
        $this->configWithSource($this->repo, '"acme:package/sso"', 'v1');
        $this->run_('sync');
        $this->commitSource($this->repo, ['CHANGELOG.md' => "# Changelog\n\n## [2.0.0]\n\n- Next.\n"], 'Next');
        $this->ageFiles();
        $before = $this->snapshot();

        $this->run_('update', ['--dry-run' => true]);
        self::assertSame($before, $this->snapshot());

        $this->run_('update');
        self::assertSame($first, $this->lock()->sources['acme']->commit, 'the tag did not move');
    }

    public function test_update_rejects_a_source_that_is_not_configured(): void {
        $this->run_('sync');

        $update = $this->run_('update', ['source' => ['nope']]);

        self::assertSame(1, $update->getStatusCode());
        self::assertStringContainsString('No source "nope" is configured', $this->flat($update->getDisplay()));
    }

    public function test_the_source_key_must_equal_the_name_in_source_json(): void {
        $other = $this->sourceRepo('other');
        $this->configWithSource($other);

        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        self::assertStringContainsString('The source key "acme" in esoul-guidelines.json does not match the name "other"', $this->flat($sync->getDisplay()));
        self::assertDirectoryDoesNotExist($this->project . '/.esoul');
    }

    public function test_two_sources_with_the_same_name_are_an_error(): void {
        $spec = json_encode(['url' => $this->repo, 'ref' => 'main'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->config('{"sources": {"acme": ' . $spec . ', "acme": ' . $spec . '}, "layers": ["general", "acme:package/sso"]}');

        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        self::assertStringContainsString('lists the source "acme" more than once', $this->flat($sync->getDisplay()));
        self::assertStringContainsString('same name', $this->flat($sync->getDisplay()));
    }

    public function test_an_unknown_source_prefix_in_layers_is_an_error(): void {
        $this->config('{"layers": ["general", "nope:package/sso"]}');

        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        self::assertStringContainsString('Layer "nope:package/sso" in esoul-guidelines.json has the prefix "nope:", but no source "nope" is configured', $this->flat($sync->getDisplay()));
        self::assertSame(1, $this->run_('check')->getStatusCode());
    }

    public function test_an_unknown_source_prefix_in_an_exception_is_an_error(): void {
        $this->config('{"layers": ["general"], "exceptions": [{"rule": "nope:package/sso/auth.md#tokens", "instead": "x", "adr": "docs/adr/1.md"}]}');

        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        self::assertStringContainsString('has the prefix "nope:", but no source "nope" is configured', $this->flat($sync->getDisplay()));
    }

    public function test_an_installer_constraint_that_excludes_the_running_version_is_an_error_naming_it(): void {
        $this->sourceRepoConstraint('^1.0');
        $this->installerVersion = '0.7.0';

        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        $message = $this->flat($sync->getDisplay());
        self::assertStringContainsString('needs esoul-guidelines "^1.0"', $message);
        self::assertStringContainsString('but this is 0.7.0', $message);
        self::assertDirectoryDoesNotExist($this->project . '/.esoul');
    }

    public function test_a_satisfied_constraint_and_a_development_build_pass_and_check_enforces_it_offline(): void {
        $this->sourceRepoConstraint('^1.0');

        $this->installerVersion = 'dev';
        self::assertSame(0, $this->run_('sync')->getStatusCode());

        $this->installerVersion = 'v1.2.0';
        self::assertSame(0, $this->run_('check')->getStatusCode());

        $this->installerVersion = '0.7.0';
        $check = $this->run_('check');
        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('needs esoul-guidelines "^1.0"', $this->flat($check->getDisplay()));
    }

    public function test_a_git_failure_shows_gits_message(): void {
        $this->configWithSource($this->tmp . '/does-not-exist');

        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        self::assertStringContainsString('git clone', $this->flat($sync->getDisplay()));
        self::assertStringContainsString('does-not-exist', $this->flat($sync->getDisplay()));
        self::assertStringContainsString('fatal', $this->flat($sync->getDisplay()));
    }

    public function test_an_injected_git_runner_replaces_the_git_cli(): void {
        $this->git = new class implements GitRunner {
            public function run(array $arguments, ?string $cwd = null): string {
                throw new GuidelinesException('fatal: unable to access (stub)');
            }
        };

        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        self::assertStringContainsString('fatal: unable to access (stub)', $this->flat($sync->getDisplay()));
    }

    public function test_an_unknown_ref_and_an_unknown_source_layer_are_errors(): void {
        $this->configWithSource($this->repo, '"acme:package/sso"', 'nope');
        $ref = $this->run_('sync');
        self::assertSame(1, $ref->getStatusCode());
        self::assertStringContainsString('has no branch or tag "nope"', $this->flat($ref->getDisplay()));

        $this->configWithSource($this->repo, '"acme:package/missing"');
        $layer = $this->run_('sync');
        self::assertSame(1, $layer->getStatusCode());
        self::assertStringContainsString('has no layer "package/missing"', $this->flat($layer->getDisplay()));
        self::assertStringContainsString('acme:package/billing', $this->flat($layer->getDisplay()), 'lists the available layers');
        self::assertDirectoryDoesNotExist($this->project . '/.esoul');
    }

    public function test_a_missing_or_invalid_source_json_is_an_error(): void {
        $files = $this->defaultSourceFiles();
        unset($files['guidelines/source.json']);
        $this->configWithSource($this->sourceRepo('nomanifest', $files));
        $missing = $this->run_('sync');
        self::assertSame(1, $missing->getStatusCode());
        self::assertStringContainsString('has no guidelines/source.json', $this->flat($missing->getDisplay()));

        $files = $this->defaultSourceFiles();
        $files['guidelines/source.json'] = '{"name": "acme", "description": "x", "installer": "*", "detect": []}';
        $this->configWithSource($this->sourceRepo('unknownkey', $files));
        $unknown = $this->run_('sync');
        self::assertSame(1, $unknown->getStatusCode());
        self::assertStringContainsString('unknown key "detect"', $this->flat($unknown->getDisplay()));
    }

    public function test_an_exception_for_a_source_rule_is_rendered_and_audited_like_a_bundled_one(): void {
        mkdir($this->project . '/docs/adr', 0777, true);
        file_put_contents($this->project . '/docs/adr/0001-tokens.md', "# ADR-1\n");
        $exceptions = [['rule' => 'acme:package/sso/auth.md#tokens', 'instead' => 'Tokens live 30 minutes here.', 'adr' => 'docs/adr/0001-tokens.md']];
        $config = $this->json('esoul-guidelines.json');
        $this->config(json_encode($config + ['exceptions' => $exceptions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $sync = $this->run_('sync');

        self::assertSame(0, $sync->getStatusCode(), $sync->getDisplay());
        self::assertStringNotContainsString('Problems with the project exceptions', $sync->getDisplay());
        self::assertStringContainsString("## Tokens\n\n> **Project exception:** Tokens live 30 minutes here. ([ADR](../../docs/adr/0001-tokens.md))", ManagedBlock::find($this->read('docs/project_guidelines/auth.md'), 'auth')['content'] ?? '');
        self::assertSame(0, $this->run_('check')->getStatusCode());

        unlink($this->project . '/docs/adr/0001-tokens.md');
        $noAdr = $this->run_('check');
        self::assertSame(1, $noAdr->getStatusCode());
        self::assertStringContainsString('ADR file "docs/adr/0001-tokens.md" does not exist', $this->flat($noAdr->getDisplay()));

        file_put_contents($this->project . '/docs/adr/0001-tokens.md', "# ADR-1\n");
        $exceptions[0]['rule'] = 'acme:package/sso/auth.md#renamed';
        $this->config(json_encode($config + ['exceptions' => $exceptions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $renamed = $this->run_('check');
        self::assertSame(1, $renamed->getStatusCode());
        self::assertStringContainsString('acme:package/sso/auth.md has no heading "#renamed"', $this->flat($renamed->getDisplay()));
    }

    public function test_an_exception_for_a_security_and_data_rule_of_a_source_is_refused(): void {
        $files = $this->defaultSourceFiles('guarded');
        $files['guidelines/package/sso/security-and-data.md'] = "# Project guidelines - Security and data\n\n## Secrets\n\nNo secrets in logs.\n";
        $this->configWithSource($this->sourceRepo('guarded', $files));
        $config = $this->json('esoul-guidelines.json');
        $config['sources'] = ['guarded' => ['url' => $this->tmp . '/sources/guarded', 'ref' => 'main']];
        $config['layers'] = ['general', 'guarded:package/sso'];
        mkdir($this->project . '/docs/adr', 0777, true);
        file_put_contents($this->project . '/docs/adr/0001-x.md', "# ADR-1\n");
        $config['exceptions'] = [['rule' => 'guarded:package/sso/security-and-data.md#secrets', 'instead' => 'Secrets may be logged.', 'adr' => 'docs/adr/0001-x.md']];
        $this->config(json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->layer('general', 'security-and-data.md', "# Project guidelines - Security and data\n\n## Secrets\n\nNever commit secrets.\n");

        $sync = $this->run_('sync');

        self::assertSame(0, $sync->getStatusCode(), $sync->getDisplay());
        self::assertStringContainsString('refused', $this->flat($sync->getDisplay()));
        self::assertStringNotContainsString('Project exception', $this->read('docs/project_guidelines/security-and-data.md'));
        $check = $this->run_('check');
        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('Exception "guarded:package/sso/security-and-data.md#secrets": refused', $this->flat($check->getDisplay()));
    }

    public function test_list_shows_source_layers_with_their_prefix_once_the_copy_is_present(): void {
        $before = $this->run_('list');
        self::assertStringContainsString('No copy of the source acme', $this->flat($before->getDisplay()));
        self::assertStringNotContainsString('acme:package/sso', $before->getDisplay());

        $this->run_('sync');
        $after = $this->run_('list');

        self::assertSame(0, $after->getStatusCode());
        self::assertMatchesRegularExpression('/\*\s+acme:package\/sso\s+auth\.md, testing\.md/', $after->getDisplay());
        self::assertStringNotContainsString('acme:package/billing', $after->getDisplay(), 'only the copied layers are known');
    }

    public function test_check_fails_on_a_selected_layer_without_an_md_file_and_still_checks_the_lock_and_the_drift(): void {
        $this->run_('sync');
        $copy = $this->project . '/' . self::COPY;
        $lock = (string) file_get_contents($this->project . '/esoul-guidelines.lock');

        // Only a non-markdown file is left of the layer: it is no layer, so it cannot be rendered.
        new Filesystem()->remove($copy . '/package');
        mkdir($copy . '/package/sso', 0777, true);
        file_put_contents($copy . '/package/sso/notes.txt', 'notes');
        $auth = $this->project . '/docs/project_guidelines/auth.md';
        file_put_contents($auth, str_replace('5 minutes', '50 minutes', (string) file_get_contents($auth)));

        $withLock = $this->flat($this->run_('check')->getDisplay());
        self::assertStringContainsString('the selected layer "acme:package/sso" is missing from the copy', $withLock);
        self::assertStringContainsString('managed block "' . $this->lock()->files['docs/project_guidelines/auth.md']->block . '" was edited locally', $withLock, 'the drift is still checked');

        unlink($this->project . '/esoul-guidelines.lock');
        $withoutLock = $this->run_('check');
        self::assertSame(1, $withoutLock->getStatusCode());
        self::assertStringContainsString('esoul-guidelines.lock not found', $this->flat($withoutLock->getDisplay()));
        self::assertNotSame('', $lock);
    }

    public function test_a_source_layer_that_is_a_symlink_in_the_repository_is_refused_and_no_clone_is_left(): void {
        symlink('/etc/hosts', $this->repo . '/guidelines/package/sso/tokens.md');
        $this->gitIn($this->repo, ['add', '--all']);
        $this->gitIn($this->repo, ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Link']);
        $before = $this->tempClones();

        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        self::assertStringContainsString('symbolic links (guidelines/package/sso/tokens.md)', $this->flat($sync->getDisplay()));
        self::assertDirectoryDoesNotExist($this->project . '/.esoul');
        self::assertSame($before, $this->tempClones());
    }

    public function test_a_symlink_in_the_copy_is_never_read_and_check_fails(): void {
        $this->run_('sync');
        $path = $this->project . '/' . self::COPY . '/package/sso/auth.md';
        unlink($path);
        symlink($this->repo . '/guidelines/package/sso/auth.md', $path);

        self::assertNull(CopyTree::hash($this->project . '/' . self::COPY, 'package/sso/auth.md'));
        self::assertSame(1, $this->run_('check')->getStatusCode());
    }

    public function test_a_failure_that_is_not_a_git_error_still_removes_the_clone(): void {
        $git = new class (new ProcessGitRunner()) implements GitRunner {
            public function __construct(private readonly GitRunner $inner) {
            }

            public function run(array $arguments, ?string $cwd = null): string {
                if (($arguments[0] ?? '') === 'checkout') {
                    throw new RuntimeException('timed out');
                }

                return $this->inner->run($arguments, $cwd);
            }
        };
        $before = $this->tempClones();

        try {
            new SourceFetcher($git)->fetch($this->repo, 'main');
            self::fail('The fetch should have failed.');
        } catch (RuntimeException $e) {
            self::assertSame('timed out', $e->getMessage());
        }

        self::assertSame($before, $this->tempClones());
    }

    public function test_git_ignores_the_repository_variables_of_the_parent_process(): void {
        putenv('GIT_DIR=' . $this->tmp . '/not-a-repository');
        try {
            $head = new ProcessGitRunner()->run(['rev-parse', 'HEAD'], $this->repo);
        } finally {
            putenv('GIT_DIR');
        }

        self::assertSame(trim($this->gitIn($this->repo, ['rev-parse', 'HEAD'])), trim($head));
    }

    public function test_pruning_does_not_walk_into_a_symlinked_directory_outside_the_copy(): void {
        $this->configWithSource($this->repo, '"acme:package/sso", "acme:package/billing"');
        $this->run_('sync');
        $outside = $this->tmp . '/outside';
        mkdir($outside . '/empty', 0777, true);
        symlink($outside, $this->project . '/.esoul/guidelines/link');
        $this->configWithSource($this->repo);

        $sync = $this->run_('sync');

        self::assertSame(0, $sync->getStatusCode(), $sync->getDisplay());
        self::assertDirectoryDoesNotExist($this->project . '/' . self::COPY . '/package/billing');
        self::assertDirectoryExists($outside . '/empty');
    }

    public function test_a_changed_ref_does_not_discard_a_hand_edited_copy_but_a_clean_one_follows_it(): void {
        $this->run_('sync');
        $this->gitIn($this->repo, ['tag', 'v1']);
        $path = $this->project . '/' . self::COPY . '/package/sso/auth.md';
        $original = (string) file_get_contents($path);
        file_put_contents($path, $original . "\nHand edit.\n");
        $this->configWithSource($this->repo, '"acme:package/sso"', 'v1');

        $refused = $this->run_('sync');

        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('was edited by hand', $this->flat($refused->getDisplay()));
        self::assertSame($original . "\nHand edit.\n", file_get_contents($path));

        file_put_contents($path, $original);
        $clean = $this->run_('sync');

        self::assertSame(0, $clean->getStatusCode(), $clean->getDisplay());
        self::assertSame('v1', $this->lock()->sources['acme']->ref);
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_a_trusted_copy_whose_manifest_needs_a_newer_installer_is_an_error_like_in_check(): void {
        $this->sourceRepoConstraint('^0.8');
        $this->installerVersion = 'dev';
        self::assertSame(0, $this->run_('sync')->getStatusCode());
        $calls = $this->recorder->calls;

        $this->installerVersion = '0.7.0';
        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        self::assertStringContainsString('needs esoul-guidelines "^0.8"', $this->flat($sync->getDisplay()));
        self::assertSame($calls, $this->recorder->calls, 'the copy was judged without git');
    }

    public function test_update_at_the_same_commit_after_deselecting_a_layer_leaves_a_lock_without_its_files(): void {
        $this->configWithSource($this->repo, '"acme:package/sso", "acme:package/billing"');
        $this->run_('sync');
        $this->configWithSource($this->repo);

        $update = $this->run_('update');

        self::assertSame(0, $update->getStatusCode(), $update->getDisplay());
        self::assertDirectoryDoesNotExist($this->project . '/' . self::COPY . '/package/billing');
        self::assertArrayNotHasKey('package/billing/invoices.md', $this->lock()->sources['acme']->files);
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_removing_a_source_refuses_a_copy_with_edited_or_added_files_until_forced(): void {
        $this->run_('sync');
        $copy = $this->project . '/' . self::COPY;
        file_put_contents($copy . '/package/sso/auth.md', "# Edited\n");
        file_put_contents($copy . '/package/sso/mine.md', "# Mine\n");
        $this->config('{"layers": ["general", "framework/laravel"]}');

        $refused = $this->run_('sync');

        self::assertSame(1, $refused->getStatusCode());
        $message = $this->flat($refused->getDisplay());
        self::assertStringContainsString(self::COPY . '/package/sso/auth.md was edited by hand', $message);
        self::assertStringContainsString(self::COPY . '/package/sso/mine.md was added by hand', $message);
        self::assertFileExists($copy . '/package/sso/mine.md');

        $forced = $this->run_('sync', ['--force' => true]);

        self::assertSame(0, $forced->getStatusCode(), $forced->getDisplay());
        self::assertDirectoryDoesNotExist($copy);
    }

    public function test_deselecting_a_layer_refuses_when_one_of_its_copied_files_was_edited(): void {
        $this->configWithSource($this->repo, '"acme:package/sso", "acme:package/billing"');
        $this->run_('sync');
        $invoices = $this->project . '/' . self::COPY . '/package/billing/invoices.md';
        file_put_contents($invoices, "# Mine\n");
        $this->configWithSource($this->repo);

        $sync = $this->run_('sync');

        self::assertSame(1, $sync->getStatusCode());
        self::assertStringContainsString(self::COPY . '/package/billing/invoices.md was edited by hand', $this->flat($sync->getDisplay()));
        self::assertSame("# Mine\n", file_get_contents($invoices));
    }

    public function test_a_source_with_a_numeric_name_syncs_checks_and_updates(): void {
        new Filesystem()->remove($this->repo);
        $this->repo = $this->sourceRepo('123');
        $this->config(json_encode(['sources' => ['123' => ['url' => $this->repo]], 'layers' => ['general', '123:package/sso']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $sync = $this->run_('sync');
        self::assertSame(0, $sync->getStatusCode(), $sync->getDisplay());
        self::assertSame(0, $this->run_('check')->getStatusCode());
        self::assertSame(0, $this->run_('update')->getStatusCode());
        self::assertSame(0, $this->run_('update', ['source' => ['123']])->getStatusCode());
        self::assertSame(0, $this->run_('list')->getStatusCode());
        self::assertSame(['package/sso/auth.md', 'package/sso/testing.md', 'source.json'], CopyTree::files($this->project . '/.esoul/guidelines/123'));
    }

    /** @return list<string> the temporary clones that fetching leaves behind */
    private function tempClones(): array {
        $found = glob(sys_get_temp_dir() . '/esoul-guidelines-source-*');

        return $found === false ? [] : $found;
    }

    private function sourceRepoConstraint(string $installer): void {
        new Filesystem()->remove($this->repo);
        $this->repo = $this->sourceRepo('acme', null, $installer);
        $this->configWithSource($this->repo);
    }
}
