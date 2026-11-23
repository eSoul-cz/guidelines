<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\PackageVersion;

final class PackageVersionTest extends TestCase
{
    public function test_the_version_of_a_phar_comes_from_its_stamp_file(): void {
        $stamp = $this->tmp . '/VERSION';

        self::assertSame('dev', PackageVersion::fromStamp($stamp));

        file_put_contents($stamp, "v0.3.1\n");
        self::assertSame('v0.3.1', PackageVersion::fromStamp($stamp));

        file_put_contents($stamp, '');
        self::assertSame('dev', PackageVersion::fromStamp($stamp));
    }
}
