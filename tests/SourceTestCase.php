<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\Source\ProcessGitRunner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Tests with guideline sources: real git repositories in a temp directory (file paths as URLs, no network). */
abstract class SourceTestCase extends TestCase
{
    protected RecordingGit $recorder;

    protected function setUp(): void {
        parent::setUp();
        $this->recorder = new RecordingGit(new ProcessGitRunner());
        $this->git = $this->recorder;
        $this->seedLayers();
        mkdir($this->tmp . '/sources', 0777, true);
    }

    /**
     * Creates a source repository with one commit and returns its path, which is its URL.
     *
     * @param array<string, string> $files path in the repository => content, replacing the default files
     */
    protected function sourceRepo(string $name = 'acme', ?array $files = null, string $installer = '*'): string {
        $dir = $this->tmp . '/sources/' . $name;
        mkdir($dir, 0777, true);
        $this->gitIn($dir, ['init', '--quiet', '-b', 'main']);
        $this->commitSource($dir, $files ?? $this->defaultSourceFiles($name, $installer), 'Initial');

        return $dir;
    }

    /** @return array<string, string> */
    protected function defaultSourceFiles(string $name = 'acme', string $installer = '*'): array {
        return [
            'guidelines/source.json' => json_encode(['name' => $name, 'description' => 'Rules of ' . $name, 'installer' => $installer], JSON_THROW_ON_ERROR),
            'guidelines/package/sso/auth.md' => "---\ntitle: Auth\nsummary: Single sign-on.\n---\n# Project guidelines - Auth\n\n## Tokens\n\nTokens expire after 5 minutes.\n",
            'guidelines/package/sso/testing.md' => "# Project guidelines - Testing\n\n## Writing tests\n\n<!-- replace -->\nAcme replaces the writing rules.\n\n## Flaky tests\n\nAcme adds a quarantine list.\n",
            'guidelines/package/billing/invoices.md' => "# Project guidelines - Invoices\n\n## Numbering\n\nGapless.\n",
            'CHANGELOG.md' => "# Changelog\n\n## [1.0.0]\n\n- First release.\n",
        ];
    }

    /**
     * Commits the files (a null content deletes the file) and returns the new commit.
     *
     * @param array<string, string|null> $files
     */
    protected function commitSource(string $dir, array $files, string $message): string {
        foreach ($files as $path => $content) {
            if ($content === null) {
                unlink($dir . '/' . $path);
                continue;
            }
            if ( ! is_dir(dirname($dir . '/' . $path))) {
                mkdir(dirname($dir . '/' . $path), 0777, true);
            }
            file_put_contents($dir . '/' . $path, $content);
        }
        $this->gitIn($dir, ['add', '--all']);
        $this->gitIn($dir, ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', $message]);

        return trim($this->gitIn($dir, ['rev-parse', 'HEAD']));
    }

    /** @param list<string> $arguments */
    protected function gitIn(string $dir, array $arguments): string {
        return new ProcessGitRunner()->run($arguments, $dir);
    }

    /** The project config with one source `acme` and the given layers after `general` and `framework/laravel`. */
    protected function configWithSource(string $url, string $layers = '"acme:package/sso"', string $ref = 'main'): void {
        $this->config(json_encode(['sources' => ['acme' => ['url' => $url, 'ref' => $ref]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        $config = $this->json('esoul-guidelines.json');
        $extra = json_decode('[' . $layers . ']', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($extra);
        $config['layers'] = array_merge(['general', 'framework/laravel'], $extra);
        $this->config(json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }

    protected function lock(): Lock {
        $lock = Lock::load($this->project);
        self::assertNotNull($lock);

        return $lock;
    }

    /** Sets every file of the project to an old modification time, so that a later rewrite shows in {@see snapshot()}. */
    protected function ageFiles(): void {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->project, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                touch($file->getPathname(), 946684800);
            }
        }
        clearstatcache();
    }

    /** @return array<string, string> every file of the project (path => sha256 and modification time), to prove that a command changed nothing */
    protected function snapshot(): array {
        clearstatcache();
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->project, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[substr($file->getPathname(), strlen($this->project) + 1)] = hash_file('sha256', $file->getPathname()) . '@' . $file->getMTime();
            }
        }
        ksort($files);

        return $files;
    }

    /** The console wraps long messages; compare them with whitespace collapsed. */
    protected function flat(string $display): string {
        return trim((string) preg_replace('/\s+/', ' ', $display));
    }
}
