<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Tests;

use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Sync\ManagedBlock;
use PHPUnit\Framework\TestCase;

final class ManagedBlockTest extends TestCase
{
    public function testReplaceKeepsEverythingOutsideTheBlockByteForByte(): void
    {
        $text = "intro\r\n\r\n" . ManagedBlock::render('testing', 'old') . "\n\nlocal notes\twith tab\n";

        $replaced = ManagedBlock::replace($text, 'testing', "new\n\ncontent");

        self::assertSame("intro\r\n\r\n" . ManagedBlock::render('testing', "new\n\ncontent") . "\n\nlocal notes\twith tab\n", $replaced);
    }

    public function testOnlyTheNamedBlockIsTouched(): void
    {
        $text = ManagedBlock::render('a', 'A') . "\n" . ManagedBlock::render('b', 'B');

        self::assertSame(ManagedBlock::render('a', 'A2') . "\n" . ManagedBlock::render('b', 'B'), ManagedBlock::replace($text, 'a', 'A2'));
    }

    public function testReplaceReturnsNullWithoutBlockAndUnterminatedBlockFails(): void
    {
        self::assertNull(ManagedBlock::replace('plain', 'x', 'y'));

        $this->expectException(GuidelinesException::class);
        ManagedBlock::find(ManagedBlock::begin('x') . "\nno end", 'x');
    }

    public function testHashIgnoresLineEndingsAndSurroundingBlankLines(): void
    {
        self::assertSame(ManagedBlock::hash("a\nb"), ManagedBlock::hash("\r\na\r\nb\r\n\r\n"));
        self::assertNotSame(ManagedBlock::hash("a\nb"), ManagedBlock::hash("a\nc"));
    }

    public function testRemoveCollapsesTheGapAndKeepsLocalContent(): void
    {
        $text = "before\n\n" . ManagedBlock::render('x', 'X') . "\n\nafter\n";

        self::assertSame("before\n\nafter\n", ManagedBlock::remove($text, 'x'));
        self::assertSame('', ManagedBlock::remove(ManagedBlock::render('x', 'X') . "\n", 'x'));
    }
}
