<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Config;

/**
 * What `json_decode($json, true)` loses about one member of the top-level object of a document that is already known
 * to be valid JSON: the last of two equal keys is kept, and `{"0": …}` cannot be told from `[…]`.
 */
final class JsonObjectKeys
{
    private int $pos = 0;

    /** @var list<string> */
    private array $duplicates = [];

    private function __construct(private readonly string $json, private readonly string $member) {
    }

    /**
     * @return list<string> the keys that occur more than once in the object that is the value of `$member`
     */
    public static function duplicates(string $json, string $member): array {
        $reader = new self($json, $member);
        $reader->value(0, false);

        return array_values(array_unique($reader->duplicates));
    }

    /** Whether `$member` is a JSON object (`{…}`), not an array: only the syntax tells `{"0": {…}}` from `[{…}]`. */
    public static function isObject(string $json, string $member): bool {
        $data = json_decode($json);

        return is_object($data) && is_object(get_object_vars($data)[$member] ?? null);
    }

    private function value(int $depth, bool $collect): void {
        $this->skipSpace();
        $char = $this->json[$this->pos] ?? '';

        if ($char === '{') {
            $this->object($depth, $collect);
        } elseif ($char === '[') {
            $this->pos++;
            $this->skipSpace();
            if (($this->json[$this->pos] ?? '') === ']') {
                $this->pos++;

                return;
            }
            do {
                $this->value($depth + 1, false);
                $this->skipSpace();
                $more = ($this->json[$this->pos] ?? '') === ',';
                $this->pos++;
            } while ($more);
        } elseif ($char === '"') {
            $this->string();
        } else {
            while ($this->pos < strlen($this->json) && ! str_contains(",}] \t\r\n", $this->json[$this->pos])) {
                $this->pos++;
            }
        }
    }

    private function object(int $depth, bool $collect): void {
        $this->pos++;
        $this->skipSpace();
        if (($this->json[$this->pos] ?? '') === '}') {
            $this->pos++;

            return;
        }

        $seen = [];
        do {
            $this->skipSpace();
            $key = $this->string();
            if ($collect) {
                if (isset($seen[$key])) {
                    $this->duplicates[] = $key;
                }
                $seen[$key] = true;
            }
            $this->skipSpace();
            $this->pos++; // ':'
            $this->value($depth + 1, $depth === 0 && $key === $this->member);
            $this->skipSpace();
            $more = ($this->json[$this->pos] ?? '') === ',';
            $this->pos++;
        } while ($more);
    }

    private function string(): string {
        $start = $this->pos;
        $this->pos++;
        while ($this->pos < strlen($this->json) && $this->json[$this->pos] !== '"') {
            $this->pos += $this->json[$this->pos] === '\\' ? 2 : 1;
        }
        $this->pos++;
        $decoded = json_decode(substr($this->json, $start, $this->pos - $start));

        return is_string($decoded) ? $decoded : '';
    }

    private function skipSpace(): void {
        while ($this->pos < strlen($this->json) && str_contains(" \t\r\n", $this->json[$this->pos])) {
            $this->pos++;
        }
    }
}
