<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Markdown;

/** An H2 section: heading text, normalised key and body (without the heading line). */
final readonly class Section
{
    public function __construct(
        public string $heading,
        public string $key,
        public string $body,
    ) {
    }

    public static function normaliseKey(string $heading): string {
        $heading = str_replace(['`', '*'], '', $heading);
        $heading = preg_replace('/\s+/u', ' ', $heading) ?? $heading;

        return mb_strtolower(rtrim(trim($heading), ':'));
    }
}
