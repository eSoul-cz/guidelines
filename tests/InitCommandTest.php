<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

final class InitCommandTest extends TestCase
{
    public function test_no_interaction_accepts_the_detected_stack_and_skips_missing_layers(): void {
        $this->seedLayers();
        $this->layer('package/inertia-vue', 'frontend.md', "# Project guidelines - Frontend\n");
        file_put_contents($this->project . '/composer.json', '{"require":{"laravel/framework":"^13.0","lunarphp/lunar":"^1.0"}}');
        file_put_contents($this->project . '/package.json', '{"dependencies":{"@inertiajs/vue3":"^3.0"}}');

        $tester = $this->run_('init');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'framework/laravel', 'package/inertia-vue']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
        self::assertStringContainsString('package/lunar', $tester->getDisplay());
    }

    public function test_detects_the_laravel_inertia_layer_only_with_both_packages(): void {
        $this->seedLayers();
        $this->layer('package/laravel-inertia', 'frontend.md', "# Project guidelines - Frontend\n");
        $this->layer('package/inertia-vue', 'frontend.md', "# Project guidelines - Frontend\n");
        file_put_contents($this->project . '/composer.json', '{"require":{"laravel/framework":"^13.0","inertiajs/inertia-laravel":"^3.0"}}');
        file_put_contents($this->project . '/package.json', '{"dependencies":{"@inertiajs/vue3":"^3.0"}}');

        $tester = $this->run_('init');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'framework/laravel', 'package/inertia-vue', 'package/laravel-inertia']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );

        file_put_contents($this->project . '/composer.json', '{"require":{"inertiajs/inertia-laravel":"^3.0"}}');
        unlink($this->project . '/esoul-guidelines.json');
        $this->run_('init');

        self::assertSame(
            ['layers' => ['general', 'package/inertia-vue']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_detects_the_filament_layer(): void {
        $this->seedLayers();
        $this->layer('package/filament', 'architecture.md', "# Project guidelines - Architecture\n");
        file_put_contents($this->project . '/composer.json', '{"require":{"laravel/framework":"^13.0","filament/filament":"^5.0"}}');

        $tester = $this->run_('init');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'framework/laravel', 'package/filament']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_detects_the_testing_package_layers(): void {
        $this->seedLayers();
        $this->layer('package/mutation-testing', 'testing.md', "# Project guidelines - Testing\n");
        $this->layer('package/test-evidence', 'testing.md', "# Project guidelines - Testing\n");
        file_put_contents($this->project . '/composer.json', '{"require":{"laravel/framework":"^13.0"},"require-dev":{"infection/infection":"^0.35","esoul-cz/phpunit-test-evidence":"^0.1"}}');

        $tester = $this->run_('init');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'framework/laravel', 'package/mutation-testing', 'package/test-evidence']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_detects_the_lsr_cqrs_layer_after_the_framework(): void {
        $this->seedLayers();
        $this->layer('framework/lsr', 'testing.md', "# Project guidelines - Testing\n");
        $this->layer('package/lsr-cqrs', 'cqrs.md', "# Project guidelines - CQRS\n");
        $this->layer('package/mutation-testing', 'testing.md', "# Project guidelines - Testing\n");
        file_put_contents($this->project . '/composer.json', '{"require":{"lsr/cqrs":"^0.1","lsr/core":"^0.7"},"require-dev":{"infection/infection":"^0.35"}}');

        $tester = $this->run_('init');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'framework/lsr', 'package/lsr-cqrs', 'package/mutation-testing']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    private function seedProfile(): void {
        $this->seedLayers();
        $this->layer('profile/internal', 'testing.md', "# Project guidelines - Testing\n\n## Flaky tests\n\n<!-- replace -->\nRerun once.\n");
    }

    public function test_interactive_internal_choice_writes_the_profile_layer_after_general(): void {
        $this->seedProfile();
        file_put_contents($this->project . '/composer.json', '{"require":{"laravel/framework":"^13.0"}}');

        // Answers: repository kind, then the stack layers (accept the detected default).
        $tester = $this->runInteractive('init', ['internal', '']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'profile/internal', 'framework/laravel']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_interactive_internal_repository_without_a_stack_is_saved_with_no_stack_layers(): void {
        $this->seedProfile();

        // Nothing is detected, so the default answer is the explicit "none" entry.
        $default = $this->runInteractive('init', ['internal', '']);

        self::assertSame(0, $default->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'profile/internal']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );

        unlink($this->project . '/esoul-guidelines.json');
        $this->runInteractive('init', ['internal', '0']);

        self::assertSame(
            ['layers' => ['general', 'profile/internal']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_interactive_stack_layers_can_still_be_picked_by_number(): void {
        $this->seedProfile();

        // 0 is "none", 1 is framework/laravel.
        $this->runInteractive('init', ['internal', '1']);

        self::assertSame(
            ['layers' => ['general', 'profile/internal', 'framework/laravel']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_interactive_client_is_the_default_and_adds_no_profile(): void {
        $this->seedProfile();
        file_put_contents($this->project . '/composer.json', '{"require":{"laravel/framework":"^13.0"}}');

        $tester = $this->runInteractive('init', ['', '']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'framework/laravel']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_interactive_choice_is_not_offered_without_a_profile_layer(): void {
        $this->seedLayers();
        file_put_contents($this->project . '/composer.json', '{"require":{"laravel/framework":"^13.0"}}');

        $tester = $this->runInteractive('init', ['']);

        self::assertStringNotContainsString('internal repository', $tester->getDisplay());
        self::assertSame(
            ['layers' => ['general', 'framework/laravel']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_no_interaction_defaults_to_client_and_never_detects_internal(): void {
        $this->seedProfile();
        file_put_contents($this->project . '/composer.json', '{"name":"esoul-cz/guidelines","require":{"laravel/framework":"^13.0"}}');

        $tester = $this->run_('init');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'framework/laravel']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_no_interaction_keeps_an_existing_internal_profile(): void {
        $this->seedProfile();
        $this->config('{"layers":["general","profile/internal","framework/laravel"],"exclude":["tools"]}');

        $tester = $this->run_('init');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'profile/internal', 'framework/laravel'], 'exclude' => ['tools']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_interactive_choice_can_switch_an_existing_internal_config_to_client(): void {
        $this->seedProfile();
        $this->config('{"layers":["general","profile/internal","framework/laravel"]}');

        $tester = $this->runInteractive('init', ['client', '']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'framework/laravel']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
    }

    public function test_sync_with_the_profile_layer_replaces_a_general_section_and_keeps_framework_additions(): void {
        $this->seedProfile();
        $this->layer('framework/laravel', 'testing.md', "---\ntitle: Testing\n---\n# Project guidelines - Testing\n\n## Flaky tests\n\nLaravel flaky rule.\n");
        $this->config('{"layers":["general","profile/internal","framework/laravel"]}');

        $tester = $this->run_('sync');

        self::assertSame(0, $tester->getStatusCode());
        $testing = $this->read('docs/project_guidelines/testing.md');
        self::assertStringNotContainsString('Fix them.', $testing);
        self::assertStringContainsString("## Flaky tests\n\n<!-- from: profile/internal -->\nRerun once.\n\n<!-- from: framework/laravel -->\nLaravel flaky rule.", $testing);
        self::assertStringContainsString("## Writing tests\n\nGeneral rule.", $testing);
    }
}
