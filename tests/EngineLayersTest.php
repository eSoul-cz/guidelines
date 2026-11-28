<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Layer\LayerRepository;
use Esoul\Guidelines\Merge\Merger;
use PHPUnit\Framework\TestCase;

/** The bundled `package/mariadb` and `package/postgres` layers against `framework/laravel`, which keeps only the engine-neutral rules. */
final class EngineLayersTest extends TestCase
{
    public function test_laravel_alone_names_no_engine_limit_or_pitfall(): void {
        $laravel = $this->render(['framework/laravel']);

        foreach (['architecture', 'testing', 'tools'] as $name) {
            self::assertStringNotContainsString('64 characters', $laravel[$name], $name);
            self::assertStringNotContainsString('63 bytes', $laravel[$name], $name);
        }
        self::assertStringNotContainsString('ONLY_FULL_GROUP_BY', $laravel['tools']);
        self::assertStringContainsString('## Persistence', $laravel['architecture']);
    }

    public function test_the_mariadb_layer_adds_its_rules_next_to_the_laravel_ones(): void {
        $output = $this->render(['framework/laravel', 'package/mariadb']);

        self::assertStringContainsString('## MariaDB and MySQL', $output['architecture']);
        self::assertStringContainsString('within 64 characters', $output['architecture']);
        self::assertStringContainsString('native `ENUM`', $output['architecture']);
        self::assertStringContainsString('identifier limit: 64 characters', $output['testing']);
        self::assertStringContainsString('ONLY_FULL_GROUP_BY', $output['tools']);
        self::assertStringNotContainsString('63 bytes', $output['architecture']);
    }

    public function test_the_postgres_layer_names_the_63_byte_limit(): void {
        $output = $this->render(['framework/laravel', 'package/postgres']);

        self::assertStringContainsString('## PostgreSQL', $output['architecture']);
        self::assertStringContainsString('within 63 bytes', $output['architecture']);
        self::assertStringContainsString('identifier limit: 63 bytes', $output['testing']);
        self::assertStringNotContainsString('64 characters', $output['architecture']);
        self::assertStringNotContainsString('ONLY_FULL_GROUP_BY', $output['tools']);
    }

    /**
     * @param list<string> $layers
     * @return array<string, string> file name => merged Markdown
     */
    private function render(array $layers): array {
        $repository = LayerRepository::bundled();
        $byName = [];
        foreach (['general', ...$layers] as $layer) {
            foreach ($repository->files($layer) as $file) {
                $byName[$file->name][] = $file;
            }
        }

        return array_map(static fn (array $sources): string => new Merger()->merge($sources)->markdown, $byName);
    }
}
