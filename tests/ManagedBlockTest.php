<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Sync\ManagedBlock;
use PHPUnit\Framework\TestCase;

final class ManagedBlockTest extends TestCase
{
    public function test_replace_keeps_everything_outside_the_block_byte_for_byte(): void {
        $text = "intro\r\n\r\n" . ManagedBlock::render('testing', 'old') . "\n\nlocal notes\twith tab\n";

        $replaced = ManagedBlock::replace($text, 'testing', "new\n\ncontent");

        self::assertSame("intro\r\n\r\n" . ManagedBlock::render('testing', "new\n\ncontent") . "\n\nlocal notes\twith tab\n", $replaced);
    }

    public function test_only_the_named_block_is_touched(): void {
        $text = ManagedBlock::render('a', 'A') . "\n" . ManagedBlock::render('b', 'B');

        self::assertSame(ManagedBlock::render('a', 'A2') . "\n" . ManagedBlock::render('b', 'B'), ManagedBlock::replace($text, 'a', 'A2'));
    }

    public function test_replace_returns_null_without_block_and_unterminated_block_fails(): void {
        self::assertNull(ManagedBlock::replace('plain', 'x', 'y'));

        $this->expectException(GuidelinesException::class);
        ManagedBlock::find(ManagedBlock::begin('x') . "\nno end", 'x');
    }

    public function test_hash_ignores_line_endings_and_surrounding_blank_lines(): void {
        self::assertSame(ManagedBlock::hash("a\nb"), ManagedBlock::hash("\r\na\r\nb\r\n\r\n"));
        self::assertNotSame(ManagedBlock::hash("a\nb"), ManagedBlock::hash("a\nc"));
    }

    public function test_remove_collapses_the_gap_and_keeps_local_content(): void {
        $text = "before\n\n" . ManagedBlock::render('x', 'X') . "\n\nafter\n";

        self::assertSame("before\n\nafter\n", ManagedBlock::remove($text, 'x'));
        self::assertSame('', ManagedBlock::remove(ManagedBlock::render('x', 'X') . "\n", 'x'));
    }
}
