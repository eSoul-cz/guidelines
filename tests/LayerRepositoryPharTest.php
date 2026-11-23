<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Layer\LayerRepository;

/** The bundled layers live inside the PHAR, where `glob()` and other filesystem-only functions do not work. */
final class LayerRepositoryPharTest extends TestCase
{
    public function test_layers_and_files_load_from_inside_a_phar(): void {
        if ( ! extension_loaded('phar')) {
            self::markTestSkipped('The phar extension is not loaded.');
        }

        $this->seedLayers();
        $phar = $this->tmp . '/layers.phar';
        $script = sprintf('$p = new Phar(%s); $p->buildFromDirectory(%s);', var_export($phar, true), var_export($this->layersDir, true));
        exec(sprintf('%s -d phar.readonly=0 -r %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        $layers = new LayerRepository('phar://' . $phar);

        self::assertSame(['general', 'framework/laravel'], $layers->layers());
        self::assertSame(['testing'], array_map(static fn ($file): string => $file->name, $layers->files('general')));
        self::assertSame(['testing', 'tools'], array_map(static fn ($file): string => $file->name, $layers->files('framework/laravel')));
    }
}
