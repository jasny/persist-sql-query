<?php /** @noinspection PhpCSValidationInspection */

declare(strict_types=1);

namespace Jasny\Persist\Tests\SQL\Query\MySQL;

use Jasny\Persist\SQL\Query;
use PHPUnit\Framework\TestCase;

class ConvertTest extends TestCase
{
    public function testCountSimple()
    {
        $query = new Query("SELECT * FROM foo", 'mysql');
        $this->assertEquals("SELECT COUNT(*) FROM foo", (string)$query->count());
    }

    public function testCountSelect()
    {
        $query = new Query("SELECT * FROM foo INNER JOIN bar ON foo.id = bar.foo_id WHERE abc = 10 LIMIT 50", 'mysql');
        $this->assertEquals("SELECT LEAST(COUNT(*), 50) FROM foo INNER JOIN bar ON foo.id = bar.foo_id WHERE abc = 10", (string)$query->count());
    }

    public function testCountSelectOffset()
    {
        $query = new Query("SELECT * FROM foo INNER JOIN bar ON foo.id = bar.foo_id WHERE abc = 10 LIMIT 50 OFFSET 200", 'mysql');
        $this->assertEquals("SELECT LEAST(COUNT(*) - 200, 50) FROM foo INNER JOIN bar ON foo.id = bar.foo_id WHERE abc = 10", (string)$query->count());
    }

    public function testCountSelectAllRows()
    {
        $query = new Query("SELECT * FROM foo INNER JOIN bar ON foo.id = bar.foo_id WHERE abc = 10 LIMIT 50", 'mysql');
        $this->assertEquals("SELECT COUNT(*) FROM foo INNER JOIN bar ON foo.id = bar.foo_id WHERE abc = 10", (string)$query->count(Query::ALL_ROWS));
    }

    public function testCountDistinct()
    {
        $query = new Query("SELECT DISTINCT id FROM foo", 'mysql');
        $this->assertEquals("SELECT COUNT(DISTINCT id) FROM foo", (string)$query->count());
    }

    public function testCountGroupBy()
    {
        $query = new Query("SELECT * FROM foo GROUP BY abc, xyz", 'mysql');
        $this->assertEquals("SELECT COUNT(DISTINCT abc, xyz) FROM foo", (string)$query->count());
    }

    public function testCountHaving()
    {
        $query = new Query("SELECT * FROM foo GROUP BY abc, xyz HAVING COUNT(*) > 10", 'mysql');
        $this->assertEquals("SELECT COUNT(*) FROM (SELECT * FROM foo GROUP BY abc, xyz HAVING COUNT(*) > 10) AS q", (string)$query->count());
    }

    public function testCountUpdate()
    {
        $query = new Query("UPDATE foo INNER JOIN bar ON foo.id = bar.foo_id SET xyz = 20 WHERE abc = 10 LIMIT 50", 'mysql');
        $this->assertEquals("SELECT LEAST(COUNT(*), 50) FROM foo INNER JOIN bar ON foo.id = bar.foo_id WHERE abc = 10", (string)$query->count());
    }

    public function testCountDelete()
    {
        $query = new Query("DELETE FROM foo WHERE abc = 10 LIMIT 50", 'mysql');
        $this->assertEquals("SELECT LEAST(COUNT(*), 50) FROM foo WHERE abc = 10", (string)$query->count());
    }

    public function testCountDeleteJoin()
    {
        $query = new Query("DELETE foo.* FROM foo INNER JOIN bar ON foo.id = bar.foo_id WHERE abc = 10", 'mysql');
        $this->assertEquals("SELECT COUNT(*) FROM foo INNER JOIN bar ON foo.id = bar.foo_id WHERE abc = 10", (string)$query->count());
    }
}
