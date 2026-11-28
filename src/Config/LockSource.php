<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Config;

/** Lock record of one guideline source: where the committed copy came from and the hash of each copied file. */
final readonly class LockSource
{
    /**
     * @param array<string, string> $files path relative to the copy (`package/sso/auth.md`, `source.json`) => sha256
     */
    public function __construct(
        public string $url,
        public string $ref,
        /** Full commit sha the copy was made from. */
        public string $commit,
        public array $files,
    ) {
    }

    /** @return array{url: string, ref: string, commit: string, files: array<string, string>} */
    public function toArray(): array {
        $files = $this->files;
        ksort($files);

        return ['url' => $this->url, 'ref' => $this->ref, 'commit' => $this->commit, 'files' => $files];
    }
}
