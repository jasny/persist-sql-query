<?php

declare(strict_types=1);

namespace Jasny\Tests\Persist\SQL\Query\MySQL;

use Jasny\Persist\SQL\Query;
use Jasny\Persist\SQL\Query\QueryBuildException;
use Jasny\Persist\SQL\Query\QuerySplitter;
use PHPUnit\Framework\TestCase;

/**
 * Test quoting values and identifiers for MySQL.
 */
class QuoteTest extends TestCase
{
    protected QuerySplitter $splitter;

    public function setUp(): void
    {
        $this->splitter = new QuerySplitter('mysql');
    }

    //--------

    public function testQuoteValueNull()
    {
        $this->assertEquals('NULL', $this->splitter->quoteValue(null));
    }

    public function testQuoteValueNullDefault()
    {
        $this->assertEquals('DEFAULT', $this->splitter->quoteValue(null, 'DEFAULT'));
    }

    public function testQuoteValueInt()
    {
        $this->assertEquals('1', $this->splitter->quoteValue(1));
    }

    public function testQuoteValueFloat()
    {
        $this->assertEquals('1.3', $this->splitter->quoteValue(1.3));
    }

    public function testQuoteValueTrue()
    {
        $this->assertEquals('TRUE', $this->splitter->quoteValue(true));
    }

    public function testQuoteValueFalse()
    {
        $this->assertEquals('FALSE', $this->splitter->quoteValue(false));
    }

    public function testQuoteValueString()
    {
        $this->assertEquals("'test'", $this->splitter->quoteValue('test'));
    }

    public function testQuoteValueStringQuotes()
    {
        $this->assertEquals("'test \'abc\' test'", $this->splitter->quoteValue("test 'abc' test"));
    }

    public function testQuoteValueStringMultiline()
    {
        $this->assertEquals("'line1\\nline2\\nline3'", $this->splitter->quoteValue("line1\nline2\nline3"));
    }

    public function testQuoteValueArray()
    {
        $this->assertEquals(
            "(1, TRUE, 'abc', DEFAULT)",
            $this->splitter->quoteValue([1, true, "abc", null], 'DEFAULT')
        );
    }

    
    public function testQuoteIdentifierSimple()
    {
        $this->assertEquals('`test`', $this->splitter->quoteIdentifier("test"));
    }

    public function testQuoteIdentifierQuoted()
    {
        $this->assertEquals('`test`', $this->splitter->quoteIdentifier("`test`"));
    }

    public function testQuoteIdentifierTableColumn()
    {
        $this->assertEquals('`abc`.`test`', $this->splitter->quoteIdentifier("abc.test"));
    }

    public function testQuoteIdentifierTableColumnQuoted()
    {
        $this->assertEquals('`abc`.`test`', $this->splitter->quoteIdentifier("`abc`.`test`"));
    }

    public function testQuoteIdentifierWithAlias()
    {
        $this->assertEquals('`abc`.`test` AS `def`', $this->splitter->quoteIdentifier("abc.test AS def"));
    }

    public function testQuoteIdentifierFunction()
    {
        $this->assertEquals(
            'count(`abc`.`test`) AS `count`',
            $this->splitter->quoteIdentifier("count(abc.test) AS count")
        );
    }

    public function testQuoteIdentifierCast()
    {
        $this->assertEquals(
            '`qqq`, cast(`abc`.`test` AS DATETIME)',
            $this->splitter->quoteIdentifier("qqq, cast(`abc`.test AS DATETIME)")
        );
    }

    public function testQuoteIdentifierCastConfuse()
    {
        $this->assertEquals(
            '`qqq`, cast(myfn(`abc`.`test` as `myarg`) AS DATETIME) AS `date`',
            $this->splitter->quoteIdentifier("qqq, cast(myfn(`abc`.test as myarg) AS DATETIME) AS date")
        );
    }

    public function testQuoteIdentifierExpression()
    {
        $this->assertEquals(
            '`abc`.`test` - `def`.`total`*10 AS `grandtotal`',
            $this->splitter->quoteIdentifier("abc.test - def.total*10 AS grandtotal")
        );
    }

    public function testQuoteIdentifierFail()
    {
        $identifier = "= 10) OR (xyz(fd = '33'), 20) OR (abc =";

        $this->expectException(QueryBuildException::class);
        $this->expectExceptionMessage("Unable to quote '$identifier' safely");

        $this->splitter->quoteIdentifier($identifier);
    }

    public function testQuoteIdentifierNone()
    {
        $this->assertEquals(
            'abc',
            $this->splitter->quoteIdentifier("abc", Query::QUOTE_NONE)
        );
    }

    public function testQuoteIdentifierStrict()
    {
        $this->assertEquals(
            '`abd-def*10`',
            $this->splitter->quoteIdentifier("abd-def*10", Query::QUOTE_STRICT)
        );
    }

    public function testQuoteIdentifierStrictTableColumn()
    {
        $this->assertEquals(
            '`abc`.`test-10`',
            $this->splitter->quoteIdentifier("`abc`.test-10", Query::QUOTE_STRICT)
        );
    }

    public function testQuoteIdentifierStrictFail()
    {
        $this->expectException(QueryBuildException::class);
        $this->expectExceptionMessage("Unable to quote '`abc`.`test`-10' safely");

        $this->splitter->quoteIdentifier("`abc`.`test`-10", Query::QUOTE_STRICT);
    }

    public function testQuoteIdentifierWords()
    {
        $this->assertEquals(
            '`count`(`abc`.`test`) AS `count`',
            $this->splitter->quoteIdentifier('count(`abc`.`test`) AS `count`', Query::QUOTE_WORDS)
        );
    }

    public function testIsIdentifierSimple()
    {
        $this->assertTrue($this->splitter->isIdentifier('test'));
    }

    public function testIsIdentifierQuoted()
    {
        $this->assertTrue($this->splitter->isIdentifier('`test`'));
    }

    public function testIsIdentifierTableColumn()
    {
        $this->assertTrue($this->splitter->isIdentifier('abc.test'));
    }

    public function testIsIdentifierTableColumnQuoted()
    {
        $this->assertTrue($this->splitter->isIdentifier('`abc`.`test`'));
    }

    public function testIsIdentifierStrange()
    {
        $this->assertFalse($this->splitter->isIdentifier('ta-$38.934#34@dhy'));
    }

    public function testIsIdentifierStrangeQuoted()
    {
        $this->assertTrue($this->splitter->isIdentifier('`ta-$38.934#34@dhy`'));
    }

    public function testIsIdentifierWithoutAliasAsAlias()
    {
        $this->assertFalse($this->splitter->isIdentifier('`test` AS def'));
    }

    public function testIsIdentifierWithoutAliasSpaceAlias()
    {
        $this->assertFalse($this->splitter->isIdentifier('`test` def'));
    }
}
