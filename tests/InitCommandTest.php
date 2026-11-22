<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

final class InitCommandTest extends TestCase
{
    public function test_no_interaction_accepts_the_detected_stack_and_skips_missing_layers(): void {
        $this->seedLayers();
        $this->layer('package/inertia-vue', 'frontend.md', "# Project guidelines - Frontend\n");
        file_put_contents($this->project . '/composer.json', '{"require":{"laravel/framework":"^13.0","filament/filament":"^5.0"}}');
        file_put_contents($this->project . '/package.json', '{"dependencies":{"@inertiajs/vue3":"^3.0"}}');

        $tester = $this->run_('init');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['layers' => ['general', 'framework/laravel', 'package/inertia-vue']],
            json_decode($this->read('esoul-guidelines.json'), true),
        );
        self::assertStringContainsString('package/filament', $tester->getDisplay());
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
}
