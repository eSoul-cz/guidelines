<?php

declare(strict_types=1);

use ESoul\CodingStandard\Config;
use PhpCsFixer\Finder;

return Config::create(
    Finder::create()->files()->in([__DIR__ . '/src', __DIR__ . '/tests'])->append([__DIR__ . '/bin/esoul-guidelines', __FILE__]),
    __DIR__ . '/build/php-cs-fixer.cache',
);
