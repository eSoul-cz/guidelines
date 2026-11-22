<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\Config\Lock;
use Esoul\Guidelines\GuidelinesException;
use PHPUnit\Framework\Attributes\DataProvider;

final class LockTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidLocks(): iterable {
        yield 'package_version null' => [['package_version' => null]];
        yield 'layers null' => [['layers' => null]];
        yield 'layers as a map' => [['layers' => ['primary' => 'general']]];
        yield 'exclude as a map' => [['exclude' => ['x' => 'cqrs']]];
        yield 'sources null' => [['files' => ['docs/a.md' => ['block' => 'a', 'sha256' => 'h', 'sources' => null]]]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidLocks')]
    public function test_load_rejects_invalid_values(array $override): void {
        $this->writeLock($override);

        $this->expectException(GuidelinesException::class);
        Lock::load($this->project);
    }

    public function test_missing_optional_keys_keep_their_defaults(): void {
        $this->writeLock([]);

        $lock = Lock::load($this->project);

        self::assertNotNull($lock);
        self::assertSame('dev', $lock->packageVersion);
        self::assertSame([], $lock->layers);
        self::assertSame([], $lock->exclude);
    }

    /** @param array<string, mixed> $override */
    private function writeLock(array $override): void {
        $data = $override + ['schema_version' => Lock::SCHEMA_VERSION, 'files' => []];
        file_put_contents($this->project . '/' . Lock::FILE, json_encode($data, JSON_THROW_ON_ERROR));
    }
}
