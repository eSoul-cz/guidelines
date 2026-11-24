<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Config\ProjectConfig;
use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Markdown\Anchors;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExceptionsTest extends TestCase
{
    private const string MONEY = 'framework/laravel/architecture.md#money';

    protected function setUp(): void {
        parent::setUp();
        $this->layer('general', 'architecture.md', "---\ntitle: Architecture\norder: 5\n---\n# Project guidelines - Architecture\n\n## Layers\n\nKeep layers.\n\n## Money\n\nGeneral money rule.\n");
        $this->layer('framework/laravel', 'architecture.md', "# Project guidelines - Architecture\n\n## Money\n\nUse integer minor units.\n\n### Rounding `half up`\n\nRound half up.\n\n```md\n## Money\n```\n");
        $this->layer('general', 'security-and-data.md', "---\ntitle: Security\n---\n# Project guidelines - Security and data\n\n## Secrets\n\nNever commit secrets.\n\n## Production access\n\nRead only.\n");
        $this->layer('profile/relaxed', 'architecture.md', "# Project guidelines - Architecture\n\n## Layers\n\n<!-- replace -->\nRelaxed layers.\n");
        mkdir($this->project . '/docs/adr', 0777, true);
        file_put_contents($this->project . '/docs/adr/0048-money.md', "# ADR-0048\n");
    }

    /** @param list<array<string, string>> $exceptions */
    private function configure(array $exceptions, string $layers = '"general", "framework/laravel"'): void {
        $this->config('{"layers": [' . $layers . '], "exceptions": ' . json_encode($exceptions, JSON_UNESCAPED_SLASHES) . '}');
    }

    /** The console wraps long messages; compare them with whitespace collapsed. */
    private function flat(string $display): string {
        return trim((string) preg_replace('/\s+/', ' ', $display));
    }

    /** @return array<string, string> */
    private function money(): array {
        return ['rule' => self::MONEY, 'instead' => 'Lunar float path stays (CalculateLines).', 'adr' => 'docs/adr/0048-money.md'];
    }

    public function test_config_parses_exceptions(): void {
        $this->configure([$this->money()]);

        $config = ProjectConfig::load($this->project);

        self::assertCount(1, $config->exceptions);
        $exception = $config->exceptions[0];
        self::assertSame(self::MONEY, $exception->rule);
        self::assertSame('framework/laravel/architecture.md', $exception->file);
        self::assertSame('architecture', $exception->name);
        self::assertSame('money', $exception->anchor);
        self::assertSame('docs/adr/0048-money.md', $exception->adr);
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidExceptions(): iterable {
        $valid = ['rule' => self::MONEY, 'instead' => 'x', 'adr' => 'docs/adr/1.md'];

        yield 'not a list' => [['rule' => self::MONEY], 'must be a list'];
        yield 'missing adr' => [[['rule' => self::MONEY, 'instead' => 'x']], '"adr" must be a non-empty string'];
        yield 'empty instead' => [[['rule' => self::MONEY, 'instead' => ' ', 'adr' => 'a.md']], '"instead" must be a non-empty string'];
        yield 'rule without anchor' => [[['rule' => 'framework/laravel/architecture.md', 'instead' => 'x', 'adr' => 'a.md']], 'must look like'];
        yield 'rule without layer' => [[['rule' => 'architecture.md#money', 'instead' => 'x', 'adr' => 'a.md']], 'must look like'];
        yield 'absolute adr' => [[['rule' => self::MONEY, 'instead' => 'x', 'adr' => '/etc/adr.md']], 'inside the project'];
        yield 'adr outside the project' => [[['rule' => self::MONEY, 'instead' => 'x', 'adr' => '../adr.md']], 'inside the project'];
        yield 'unknown key' => [[$valid + ['why' => 'x']], 'unknown key "why"'];
        yield 'duplicate rule' => [[$valid, $valid], 'repeats the rule'];
        yield 'not an object' => [['framework/laravel/architecture.md#money'], 'must be an object'];
    }

    /**
     * @param array<array-key, mixed> $exceptions
     */
    #[DataProvider('invalidExceptions')]
    public function test_config_rejects_invalid_exceptions(array $exceptions, string $message): void {
        $this->config(json_encode(['layers' => ['general'], 'exceptions' => $exceptions], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            ProjectConfig::load($this->project);
            self::fail('The invalid exceptions were accepted.');
        } catch (GuidelinesException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_exceptions_survive_init(): void {
        $this->configure([$this->money()]);

        $this->runInteractive('init', ['1']);

        self::assertStringContainsString('"exceptions"', $this->read('esoul-guidelines.json'));
        self::assertCount(1, ProjectConfig::load($this->project)->exceptions);
    }

    public function test_anchors_follow_the_github_slug_rules(): void {
        $body = "## Money\n\n### Rounding `half up`: rules\n\n## Money\n\n```\n## Hidden\n```\n\n#### Tax & VAT (EU)\n";

        self::assertSame(
            ['money', 'rounding-half-up-rules', 'money-1', 'tax--vat-eu'],
            array_map(static fn (array $h): string => $h['anchor'], Anchors::headings($body)),
        );
        self::assertFalse(Anchors::has($body, 'hidden'));
    }

    public function test_sync_renders_a_marker_under_the_overridden_heading_only(): void {
        $this->configure([$this->money()]);

        self::assertSame(0, $this->run_('sync')->getStatusCode());

        $architecture = $this->read('docs/project_guidelines/architecture.md');
        $marker = '> **Project exception:** Lunar float path stays (CalculateLines). ([ADR](../../docs/adr/0048-money.md))';
        self::assertSame(1, substr_count($architecture, $marker));
        // Under the Money heading of the framework layer, not under the general one with the same text.
        self::assertMatchesRegularExpression('/<!-- from: framework\/laravel -->\n' . preg_quote($marker, '/') . '\n\nUse integer minor units\./', $architecture);
        self::assertStringContainsString("## Money\n\nGeneral money rule.", $architecture);
        self::assertStringContainsString('**Project exception**', $this->read('docs/project_guidelines/index.md'));
    }

    public function test_sync_without_exceptions_renders_no_marker(): void {
        $this->configure([]);

        $this->run_('sync');

        self::assertStringNotContainsString('Project exception', $this->read('docs/project_guidelines/architecture.md'));
        self::assertStringNotContainsString('Project exception', $this->read('docs/project_guidelines/index.md'));
    }

    public function test_marker_goes_under_a_third_level_heading(): void {
        $this->configure([['rule' => 'framework/laravel/architecture.md#rounding-half-up', 'instead' => 'Round half even.', 'adr' => 'docs/adr/0048-money.md']]);

        $this->run_('sync');

        self::assertStringContainsString("### Rounding `half up`\n\n> **Project exception:** Round half even. ([ADR](../../docs/adr/0048-money.md))\n\nRound half up.", $this->read('docs/project_guidelines/architecture.md'));
    }

    public function test_marker_keeps_a_replaced_section_replaced(): void {
        $this->configure([['rule' => 'profile/relaxed/architecture.md#layers', 'instead' => 'Layers are optional here.', 'adr' => 'docs/adr/0048-money.md']], '"general", "profile/relaxed"');

        $this->run_('sync');

        $architecture = $this->read('docs/project_guidelines/architecture.md');
        self::assertStringNotContainsString('Keep layers.', $architecture);
        self::assertStringContainsString("<!-- from: profile/relaxed -->\n> **Project exception:** Layers are optional here.", $architecture);
        self::assertStringContainsString('Relaxed layers.', $architecture);
    }

    public function test_check_passes_with_valid_exceptions_and_in_sync_output(): void {
        $this->configure([$this->money()]);
        $this->run_('sync');

        $check = $this->run_('check');

        self::assertSame(0, $check->getStatusCode(), $check->getDisplay());
    }

    public function test_check_reports_a_new_exception_as_outdated_until_synced(): void {
        $this->configure([]);
        $this->run_('sync');
        $this->configure([$this->money()]);

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('Outdated', $this->flat($check->getDisplay()));
    }

    public function test_check_fails_when_the_rule_no_longer_exists(): void {
        $this->configure([$this->money()]);
        $this->run_('sync');
        $this->layer('framework/laravel', 'architecture.md', "# Project guidelines - Architecture\n\n## Currency\n\nUse integer minor units.\n");

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('has no heading "#money" any more', $this->flat($check->getDisplay()));
        self::assertStringContainsString('currency', $this->flat($check->getDisplay()));
    }

    public function test_check_fails_when_the_rule_file_no_longer_exists(): void {
        $this->configure([['rule' => 'framework/laravel/money.md#money', 'instead' => 'x', 'adr' => 'docs/adr/0048-money.md']]);
        $this->run_('sync');

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('have no file "framework/laravel/money.md"', $this->flat($check->getDisplay()));
    }

    public function test_check_fails_when_the_layer_is_not_selected(): void {
        $this->configure([$this->money()], '"general"');
        $this->run_('sync');

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('layer "framework/laravel" is not selected', $this->flat($check->getDisplay()));
    }

    public function test_check_fails_when_the_adr_file_is_missing(): void {
        $exception = $this->money();
        $exception['adr'] = 'docs/adr/0099-missing.md';
        $this->configure([$exception]);
        $this->run_('sync');

        $check = $this->run_('check');

        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('ADR file "docs/adr/0099-missing.md" does not exist', $this->flat($check->getDisplay()));
        self::assertStringNotContainsString('Drift', $this->flat($check->getDisplay()));
    }

    public function test_security_and_production_data_rules_cannot_be_excepted(): void {
        $this->configure([
            ['rule' => 'general/security-and-data.md#secrets', 'instead' => 'Secrets in Git are fine.', 'adr' => 'docs/adr/0048-money.md'],
            ['rule' => 'general/security-and-data.md#production-access', 'instead' => 'Write to production.', 'adr' => 'docs/adr/0048-money.md'],
        ], '"general"');

        $sync = $this->run_('sync');
        $check = $this->run_('check');

        self::assertSame(0, $sync->getStatusCode());
        self::assertStringContainsString('refused', $this->flat($sync->getDisplay()));
        self::assertStringNotContainsString('Project exception', $this->read('docs/project_guidelines/security-and-data.md'));
        self::assertSame(1, $check->getStatusCode());
        self::assertStringContainsString('Exception "general/security-and-data.md#secrets": refused', $this->flat($check->getDisplay()));
        self::assertStringContainsString('Exception "general/security-and-data.md#production-access": refused', $this->flat($check->getDisplay()));
    }
}
