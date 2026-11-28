<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Config\JsonObjectKeys;
use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Source\SourceManifest;
use PHPUnit\Framework\Attributes\DataProvider;

final class SourceConfigTest extends TestCase
{
    public function test_json_object_keys_finds_repeated_keys_of_one_member_only(): void {
        $json = '{"layers": ["a", {"x": 1, "x": 2}], "sources": {"acme": {"url": "u", "url": "v"}, "b": {}, "acme": {}, "c\\u0021": 1, "c!": 2}, "other": {"k": 1, "k": 2}}';

        self::assertSame(['acme', 'c!'], JsonObjectKeys::duplicates($json, 'sources'));
        self::assertSame([], JsonObjectKeys::duplicates('{"sources": {}, "layers": []}', 'sources'));
        self::assertSame([], JsonObjectKeys::duplicates('[1, 2]', 'sources'));
    }

    public function test_config_parses_sources_in_order_and_save_keeps_them(): void {
        $this->config('{"layers": ["general", "acme:package/sso"], "sources": {"zeta": {"url": "git@example.com:z.git"}, "acme": {"url": "git@example.com:a.git", "ref": "v1"}}}');

        $config = ProjectConfig::load($this->project);

        self::assertSame(['zeta', 'acme'], array_keys($config->sources));
        self::assertSame('main', $config->sources['zeta']->ref, 'ref defaults to main');
        self::assertSame('v1', $config->sources['acme']->ref);

        $config->save($this->project);

        self::assertSame(
            [
                'sources' => ['zeta' => ['url' => 'git@example.com:z.git', 'ref' => 'main'], 'acme' => ['url' => 'git@example.com:a.git', 'ref' => 'v1']],
                'layers' => ['general', 'acme:package/sso'],
            ],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSources(): iterable {
        yield 'a list' => ['{"sources": [{"url": "u"}]}', 'must be an object'];
        yield 'not an object' => ['{"sources": {"acme": "u"}}', 'must be an object with "url" and "ref"'];
        yield 'missing url' => ['{"sources": {"acme": {"ref": "main"}}}', '"url" must be a non-empty git URL'];
        yield 'option-like url' => ['{"sources": {"acme": {"url": "--upload-pack=x"}}}', '"url" must be a non-empty git URL'];
        yield 'empty ref' => ['{"sources": {"acme": {"url": "u", "ref": ""}}}', '"ref" must be a branch or a tag'];
        yield 'unknown key' => ['{"sources": {"acme": {"url": "u", "commit": "abc"}}}', 'unknown key "commit"'];
        yield 'uppercase name' => ['{"sources": {"Acme": {"url": "u"}}}', 'lowercase letters'];
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_sources_are_rejected_with_a_message(string $json, string $message): void {
        $this->config($json);

        $this->assertFailsWith($message, fn () => ProjectConfig::load($this->project));
    }

    public function test_exception_rules_and_layers_accept_the_source_prefix(): void {
        $this->config('{"sources": {"acme": {"url": "u"}}, "layers": ["general", "acme:package/sso"], "exceptions": [{"rule": "acme:package/sso/auth.md#tokens", "instead": "x", "adr": "a.md"}]}');

        $exception = ProjectConfig::load($this->project)->exceptions[0];

        self::assertSame('acme:package/sso/auth.md', $exception->file);
        self::assertSame('auth', $exception->name);
        self::assertSame('tokens', $exception->anchor);
        self::assertSame('acme', $exception->source());
    }

    public function test_layer_repository_with_a_source_reports_and_reads_prefixed_layers(): void {
        $this->layer('package/sso', 'auth.md', "# Project guidelines - Auth\n\n## Tokens\n\nText.\n");
        $this->layer('general', 'rules.md', "# Project guidelines - Rules\n");
        $repository = new LayerRepository($this->layersDir, 'acme');

        self::assertSame(['acme:general', 'acme:package/sso'], $repository->layers());
        self::assertTrue($repository->has('acme:package/sso'));
        self::assertFalse($repository->has('package/sso'));
        $file = $repository->files('acme:package/sso')[0];
        self::assertSame('acme:package/sso', $file->layer);
        self::assertSame('acme:package/sso/auth.md', $file->relativePath);

        $this->expectException(GuidelinesException::class);
        $repository->files('other:package/sso');
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function constraints(): iterable {
        yield 'caret satisfied' => ['^0.7', '0.7.3', true];
        yield 'v prefix' => ['^0.7', 'v0.7.0', true];
        yield 'caret on 0.x excludes the next minor' => ['^0.7', '0.8.0', false];
        yield 'older than required' => ['>=0.7', '0.6.2', false];
        yield 'wildcard' => ['*', '0.1.0', true];
        yield 'dev build' => ['^9.0', 'dev', true];
        yield 'dev branch' => ['^9.0', 'dev-main', true];
        yield 'git describe of an untagged build' => ['^9.0', 'v0.6.2-3-gabc1234', true];
        yield 'pre-release of the next version' => ['^1.0', '1.0.0-beta1', true];
    }

    #[DataProvider('constraints')]
    public function test_installer_constraints(string $constraint, string $version, bool $accepted): void {
        self::assertSame($accepted, SourceManifest::accepts($constraint, $version));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidManifests(): iterable {
        yield 'not json' => ['{', 'is not valid JSON'];
        yield 'a list' => ['[]', 'must contain a JSON object'];
        yield 'missing description' => ['{"name": "acme", "installer": "*"}', '"description" must be a non-empty string'];
        yield 'unknown key' => ['{"name": "acme", "description": "d", "installer": "*", "extra": 1}', 'unknown key "extra"'];
        yield 'bad name' => ['{"name": "Acme Co", "description": "d", "installer": "*"}', '"name" must be lowercase'];
        yield 'bad constraint' => ['{"name": "acme", "description": "d", "installer": "^^1"}', '"installer" is not a valid version constraint'];
    }

    #[DataProvider('invalidManifests')]
    public function test_source_json_is_validated(string $json, string $message): void {
        file_put_contents($this->tmp . '/source.json', $json);

        $this->assertFailsWith($message, fn () => SourceManifest::read($this->tmp . '/source.json', 'test'));
    }

    public function test_a_lock_of_schema_1_is_read_and_sync_rewrites_it_as_schema_2(): void {
        $this->seedLayers();
        $this->config('{"layers": ["general", "framework/laravel"]}');
        $this->run_('sync');
        $lock = $this->json('esoul-guidelines.lock');
        self::assertSame(2, $lock['schema_version']);
        $lock['schema_version'] = 1;
        file_put_contents($this->project . '/esoul-guidelines.lock', json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $loaded = Lock::load($this->project);
        self::assertNotNull($loaded);
        self::assertSame([], $loaded->sources);
        self::assertSame(0, $this->run_('check')->getStatusCode(), 'check passes on a schema-1 lock of a project without sources');

        self::assertSame(0, $this->run_('sync')->getStatusCode());

        $rewritten = $this->json('esoul-guidelines.lock');
        self::assertSame(2, $rewritten['schema_version']);
        self::assertArrayNotHasKey('sources', $rewritten, 'a project without sources has no sources in the lock');
        self::assertSame(0, $this->run_('check')->getStatusCode());
    }

    public function test_a_lock_of_an_unknown_schema_is_refused(): void {
        file_put_contents($this->project . '/esoul-guidelines.lock', '{"schema_version": 3, "files": {}}');

        $this->assertFailsWith('expected schema_version 1 or 2', fn () => Lock::load($this->project));
    }

    public function test_a_lock_of_schema_2_round_trips_its_sources(): void {
        file_put_contents($this->project . '/esoul-guidelines.lock', json_encode([
            'schema_version' => 2,
            'files' => [],
            'sources' => ['acme' => ['url' => 'u', 'ref' => 'main', 'commit' => 'abc', 'files' => ['source.json' => 'h']]],
        ], JSON_THROW_ON_ERROR));

        $lock = Lock::load($this->project);

        self::assertNotNull($lock);
        self::assertSame('abc', $lock->sources['acme']->commit);
        self::assertSame(['source.json' => 'h'], $lock->sources['acme']->files);
        self::assertStringContainsString('"commit": "abc"', $lock->toJson());
    }

    public function test_init_keeps_the_sources_and_the_source_layers(): void {
        $this->seedLayers();
        file_put_contents($this->project . '/composer.json', '{"require":{"laravel/framework":"^13.0"}}');
        $this->config('{"sources": {"acme": {"url": "u", "ref": "v2"}}, "layers": ["general", "acme:package/sso", "framework/laravel"]}');

        $tester = $this->run_('init');

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(
            ['sources' => ['acme' => ['url' => 'u', 'ref' => 'v2']], 'layers' => ['general', 'framework/laravel', 'acme:package/sso']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_interactive_init_does_not_offer_or_drop_source_layers(): void {
        $this->seedLayers();
        $this->config('{"sources": {"acme": {"url": "u"}}, "layers": ["general", "acme:package/sso"]}');

        $tester = $this->runInteractive('init', ['', '']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('] acme:package/sso', $tester->getDisplay(), 'not offered as a stack layer');
        self::assertSame(['general', 'acme:package/sso'], $this->json('esoul-guidelines.json')['layers']);
    }
}
