<?php

declare(strict_types=1);

namespace Persist\Tests\SQL\Query\MySQL;

use Persist\SQL\Query\Query;
use PHPUnit\Framework\TestCase;

class BindTest extends TestCase
{
    public function testBindNull()
    {
        $this->assertEquals(
            "UPDATE phpunit_test SET description=NULL",
            (new Query("UPDATE phpunit_test SET description=?", 'mysql'))->bind(null)->asString()
        );
    }

    public function testBindInteger()
    {
        $this->assertEquals(
            "SELECT * FROM phpunit_test WHERE status=10",
            (new Query("SELECT * FROM phpunit_test WHERE status=?", 'mysql'))->bind(10)->asString()
        );
    }

    public function testBindFloat()
    {
        $this->assertEquals(
            "SELECT * FROM phpunit_test WHERE status=33.7",
            (new Query("SELECT * FROM phpunit_test WHERE status=?", 'mysql'))->bind(33.7)->asString()
        );
    }

    public function testBindBoolean()
    {
        $this->assertEquals(
            "SELECT * FROM phpunit_test WHERE status=TRUE AND disabled=FALSE",
            (new Query("SELECT * FROM phpunit_test WHERE status=? AND disabled=?", 'mysql'))
                ->bind(true, false)
                ->asString()
        );
    }

    public function testBindString()
    {
        $this->assertEquals(
            "SELECT id, 'test' AS desc FROM phpunit_test WHERE status='ACTIVE'",
            (new Query("SELECT id, ? AS desc FROM phpunit_test WHERE status=?", 'mysql'))
                ->bind("test", "ACTIVE")
                ->asString()
        );
    }

    public function testBindStringConfuse()
    {
        $this->assertEquals(
            "SELECT id, '?' AS `desc ?`, \"?\" AS x FROM phpunit_test WHERE status='ACTIVE'",
            (new Query("SELECT id, '?' AS `desc ?`, \"?\" AS x FROM phpunit_test WHERE status=?", 'mysql'))
                ->bind("ACTIVE", "not me", "not me", "not me")
                ->asString()
        );
    }

    public function testBindStringQuote()
    {
        $this->assertEquals(
            "SELECT * FROM phpunit_test WHERE description='This is a \\'test\\''",
            (new Query("SELECT * FROM phpunit_test WHERE description=?", 'mysql'))
                ->bind("This is a 'test'")
                ->asString()
        );
    }

    public function testBindStringQuoteMultiline()
    {
        $this->assertEquals(
            "SELECT * FROM phpunit_test WHERE description='line 1\\nline 2\\nline 3.1\\tline 3.2'",
            (new Query("SELECT * FROM phpunit_test WHERE description=?", 'mysql'))
                ->bind("line 1\nline 2\nline 3.1\tline 3.2")
                ->asString()
        );
    }

    public function testBindArray()
    {
        $this->assertEquals(
            "SELECT * FROM phpunit_test WHERE description IN ('test', 10, FALSE, 'another test')",
            (new Query("SELECT * FROM phpunit_test WHERE description IN ?", 'mysql'))
                ->bind(["test", 10, false, "another test"])
                ->asString()
        );
    }

    public function testBindNamed()
    {
        $this->assertEquals(
            "SELECT id, 'test' AS desc FROM phpunit_test WHERE status='ACTIVE'",
            (new Query("SELECT id, :desc AS desc FROM phpunit_test WHERE status=:status", 'mysql'))
                ->bindNamed(["desc" => "test", "status" => "ACTIVE"])
                ->asString()
        );
    }

    public function testBindLike()
    {
        $this->assertEquals(
            "SELECT * FROM phpunit_test WHERE description LIKE '%foo%'",
            (new Query("SELECT * FROM phpunit_test WHERE description LIKE %?%", 'mysql'))
                ->bind("foo")
                ->asString()
        );
    }

    public function testBindLikeNamed()
    {
        $this->assertEquals(
            "SELECT * FROM phpunit_test WHERE description LIKE '%foo%'",
            (new Query("SELECT * FROM phpunit_test WHERE description LIKE %:desc%", 'mysql'))
                ->bindNamed(["desc" => "foo"])
                ->asString()
        );
    }
}
