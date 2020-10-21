<?php /** @noinspection PhpCSValidationInspection */

declare(strict_types=1);

namespace Persist\Tests\SQL\Query\MySQL;

use Persist\SQL\Query\Query;
use PHPUnit\Framework\TestCase;

/**
 * Test manipulating queries.
 */
class QueryTest extends TestCase
{
    // ----- WHERE

    public function testWhereSimple()
    {
        $query = (new Query("SELECT * FROM x", 'mysql'))->where('foo', 10);
        $this->assertEquals("SELECT * FROM x WHERE `foo` = 10", $query);
    }

    public function testWhereMoreThan()
    {
        $query = (new Query("SELECT * FROM x", 'mysql'))->where('foo > ?', 10);
        $this->assertEquals("SELECT * FROM x WHERE `foo` > 10", $query);
    }

    public function testWhereIsNull()
    {
        $query = (new Query("SELECT * FROM x", 'mysql'))->where('foo IS NULL');
        $this->assertEquals("SELECT * FROM x WHERE `foo` IS NULL", $query);
    }

    public function testWhereIn()
    {
        $query = (new Query("SELECT * FROM x", 'mysql'))->where('foo', [10, 20]);
        $this->assertEquals("SELECT * FROM x WHERE `foo` IN (10, 20)", $query);
    }

    public function testWhereBetween()
    {
        $query = (new Query("SELECT * FROM x", 'mysql'))->where('foo BETWEEN ? AND ?', [10, 20]);
        $this->assertEquals("SELECT * FROM x WHERE `foo` BETWEEN 10 AND 20", $query);
    }

    public function testWhereLike()
    {
        $query = (new Query("SELECT * FROM x", 'mysql'))->where('bar LIKE %?%', "blue");
        $this->assertEquals("SELECT * FROM x WHERE `bar` LIKE '%blue%'", $query);
    }

    public function testWhereTwoParams()
    {
        $query = (new Query('SELECT * FROM x'))->where("foo = ? AND bar LIKE %?%", [10, 'blue']);
        $this->assertEquals("SELECT * FROM x WHERE `foo` = 10 AND `bar` LIKE '%blue%'", $query);
    }

    public function testWhereArray()
    {
        $query = (new Query('SELECT * FROM x'))->where(["foo" => 10, "bar" => 'blue']);
        $this->assertEquals("SELECT * FROM x WHERE `foo` = 10 AND `bar` = 'blue'", $query);
    }

    public function testWhereTwoParamsArray()
    {
        $query = (new Query('SELECT * FROM x'))->where("foo IN ? AND bar LIKE %?%", [[10, 20], 'blue']);
        $this->assertEquals("SELECT * FROM x WHERE `foo` IN (10, 20) AND `bar` LIKE '%blue%'", $query);
    }


    // ----- SELECT

    public function testSelectStatementAddColumn()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->column("abc");
        $this->assertEquals("SELECT id, description, `abc` FROM `test`", (string)$query);
    }

    public function testSelectStatementAddColumnArray()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->column(["abc", "def", "ghi"], Query::APPEND);
        $this->assertEquals("SELECT id, description, `abc`, `def`, `ghi` FROM `test`", (string)$query);
    }

    public function testSelectStatementAddColumnPrepend()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->column("abc", Query::PREPEND);
        $this->assertEquals("SELECT `abc`, id, description FROM `test`", (string)$query);
    }

    public function testSelectStatementAddColumnReplace()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->column("abc", Query::REPLACE);
        $this->assertEquals("SELECT `abc` FROM `test`", (string)$query);
    }

    public function testSelectStatementReplaceTable()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE xy > 10", 'mysql');
        $query->from("abc");
        $this->assertEquals("SELECT id, description FROM `abc` WHERE xy > 10", (string)$query);
    }

    public function testSelectStatementAddTable()
    {
        // Removing the extra space between table and comma, would make the code slower.

        $query = new Query("SELECT id, description FROM `test` WHERE xy > 10", 'mysql');
        $query->from("abc", Query::APPEND);
        $this->assertEquals("SELECT id, description FROM `test` , `abc` WHERE xy > 10", (string)$query);
    }

    public function testSelectStatementInnerJoin()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->innerJoin("abc");
        $this->assertEquals("SELECT id, description FROM `test` INNER JOIN `abc`", (string)$query);
    }

    public function testSelectStatementInnerJoinOn()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->innerJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT id, description FROM `test` INNER JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectStatementLeftJoin()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE xy > 10", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT id, description FROM `test` LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest` WHERE xy > 10", (string)$query);
    }

    public function testSelectStatementLeftJoinAgain()
    {
        $query = new Query("SELECT id, description FROM `test` LEFT JOIN x ON test.x_id = x.id", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT id, description FROM (`test` LEFT JOIN x ON test.x_id = x.id) LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectStatementLeftJoinPrepend()
    {
        $query = new Query("SELECT id, description FROM `test` LEFT JOIN x ON test.x_id = x.id", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest", Query::PREPEND);
        $this->assertEquals("SELECT id, description FROM `abc` LEFT JOIN (`test` LEFT JOIN x ON test.x_id = x.id) ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectStatementRightJoin()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE xy > 10", 'mysql');
        $query->rightJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT id, description FROM `test` RIGHT JOIN `abc` ON `test`.`id` = `abc`.`idTest` WHERE xy > 10", (string)$query);
    }

    public function testSelectStatementWhereSimple()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->where("status = 1");
        $this->assertEquals("SELECT id, description FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testSelectStatementWhere()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->where("status = 1");
        $this->assertEquals("SELECT id, description FROM `test` WHERE (id > 10) AND (`status` = 1) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectStatementWherePrepend()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->where("status = 1", null, Query::PREPEND);
        $this->assertEquals("SELECT id, description FROM `test` WHERE (`status` = 1) AND (id > 10) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectStatementWhereReplace()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->where("status = 1", null, Query::REPLACE);
        $query->where("xyz = 1");
        $this->assertEquals("SELECT id, description FROM `test` WHERE (`status` = 1) AND (`xyz` = 1) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectStatementHaving()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->having("status = 1");
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING (SUM(qty) > 10) AND (`status` = 1)", (string)$query);
    }

    public function testSelectStatementGroupBySimple()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->groupBy("parent_id");
        $this->assertEquals("SELECT id, description FROM `test` GROUP BY `parent_id`", (string)$query);
    }

    public function testSelectStatementGroupBy()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->groupBy("parent_id");
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id, `parent_id` HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectStatementOrderBySimple()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->orderBy("parent_id");
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY `parent_id`", (string)$query);
    }

    public function testSelectStatementOrderByArray()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->groupBy(["test1", "test2", "test3"]);
        $this->assertEquals("SELECT id, description FROM `test` GROUP BY `test1`, `test2`, `test3`", (string)$query);
    }

    public function testSelectStatementOrderBy()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz", 'mysql');
        $query->orderBy("parent_id");
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY `parent_id`, xyz", (string)$query);
    }


    public function testSelectStatementOrderByAsc()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->orderBy("parent_id", Query::ASC);
        $this->assertEquals("SELECT id, description FROM `test` ORDER BY `parent_id` ASC", (string)$query);
    }

    public function testSelectStatementOrderByDesc()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->orderBy("parent_id", Query::DESC);
        $this->assertEquals("SELECT id, description FROM `test` ORDER BY `parent_id` DESC", (string)$query);
    }

    public function testSelectStatementOrderByAppend()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz", 'mysql');
        $query->orderBy("parent_id", Query::APPEND);
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz, `parent_id`", (string)$query);
    }

    public function testSelectStatementOrderByWithArray()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->orderBy(["name", "description", "checksum"]);
        $this->assertEquals("SELECT id, description FROM `test` ORDER BY `name`, `description`, `checksum`", (string)$query);
    }

    public function testSelectStatementWhereCriteriaEquals()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->where("status", 1);
        $this->assertEquals("SELECT id, description FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testSelectStatementWhereCriteriaGreatEq()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->where('id >= ?', 1);
        $this->assertEquals("SELECT id, description FROM `test` WHERE `id` >= 1", (string)$query);
    }

    public function testSelectStatementWhereCriteriaOr()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10", 'mysql');
        $query->where('xyz = ? OR abc = ?', [10, 20]);
        $this->assertEquals("SELECT id, description FROM `test` WHERE (id > 10) AND (`xyz` = 10 OR `abc` = 20)", (string)$query);
    }

    public function testSelectStatementWhereCriteriaIn()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->where('xyz', ['a', 'b', 'c']);
        $this->assertEquals("SELECT id, description FROM `test` WHERE `xyz` IN ('a', 'b', 'c')", (string)$query);
    }

    public function testSelectStatementWhereCriteriaBetween()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->where('xyz BETWEEN ? AND ?', [10, 12]);
        $this->assertEquals("SELECT id, description FROM `test` WHERE `xyz` BETWEEN 10 AND 12", (string)$query);
    }

    public function testSelectStatementWhereCriteriaLikeWildcard()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->where('description LIKE ?%', 'bea');
        $this->assertEquals("SELECT id, description FROM `test` WHERE `description` LIKE 'bea%'", (string)$query);
    }

    public function testSelectStatementLimit()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->limit(10);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10", (string)$query);
    }

    public function testSelectStatementLimitReplace()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 12", 'mysql');
        $query->limit(50, 30);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 50 OFFSET 30", (string)$query);
    }

    public function testSelectStatementLimitString()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 12", 'mysql');
        $query->limit("50 OFFSET 30");
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 50 OFFSET 30", (string)$query);
    }

    public function testSelectStatementPage()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $query->page(4, 10);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    public function testSelectStatementPageLimit()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 10", 'mysql');
        $query->page(4);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    public function testSelectStatementPageLimitAgain()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 4, 10", 'mysql');
        $query->page(4);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    // ---------- Select DISTINCT

    public function testSelectDistinctStatementAddColumn()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->column("abc");
        $this->assertEquals("SELECT DISTINCT id, description, `abc` FROM `test`", (string)$query);
    }

    public function testSelectDistinctStatementAddColumnArray()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->column(["abc", "def", "ghi"], Query::APPEND);
        $this->assertEquals("SELECT DISTINCT id, description, `abc`, `def`, `ghi` FROM `test`", (string)$query);
    }

    public function testSelectDistinctStatementAddColumnPrepend()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->column("abc", Query::PREPEND);
        $this->assertEquals("SELECT DISTINCT `abc`, id, description FROM `test`", (string)$query);
    }

    public function testSelectDistinctStatementAddColumnReplace()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->column("abc", Query::REPLACE);
        $this->assertEquals("SELECT DISTINCT `abc` FROM `test`", (string)$query);
    }

    public function testSelectDistinctStatementReplaceTable()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE xy > 10", 'mysql');
        $query->from("abc");
        $this->assertEquals("SELECT DISTINCT id, description FROM `abc` WHERE xy > 10", (string)$query);
    }

    public function testSelectDistinctStatementAddTable()
    {
        // Removing the extra space between table and comma, would make the code slower.

        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE xy > 10", 'mysql');
        $query->from("abc", Query::APPEND);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` , `abc` WHERE xy > 10", (string)$query);
    }

    public function testSelectDistinctStatementInnerJoin()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->innerJoin("abc");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` INNER JOIN `abc`", (string)$query);
    }

    public function testSelectDistinctStatementInnerJoinOn()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->innerJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` INNER JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectDistinctStatementLeftJoin()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE xy > 10", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest` WHERE xy > 10", (string)$query);
    }

    public function testSelectDistinctStatementLeftJoinAgain()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LEFT JOIN x ON test.x_id = x.id", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT DISTINCT id, description FROM (`test` LEFT JOIN x ON test.x_id = x.id) LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectDistinctStatementLeftJoinPrepend()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LEFT JOIN x ON test.x_id = x.id", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest", Query::PREPEND);
        $this->assertEquals("SELECT DISTINCT id, description FROM `abc` LEFT JOIN (`test` LEFT JOIN x ON test.x_id = x.id) ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectDistinctStatementRightJoin()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE xy > 10", 'mysql');
        $query->rightJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` RIGHT JOIN `abc` ON `test`.`id` = `abc`.`idTest` WHERE xy > 10", (string)$query);
    }

    public function testSelectDistinctStatementWhereSimple()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->where("status = 1");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testSelectDistinctStatementWhere()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->where("status = 1");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE (id > 10) AND (`status` = 1) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectDistinctStatementWherePrepend()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->where("status = 1", null, Query::PREPEND);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE (`status` = 1) AND (id > 10) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectDistinctStatementWhereReplace()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->where("status = 1", null, Query::REPLACE);
        $query->where("xyz = 1");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE (`status` = 1) AND (`xyz` = 1) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectDistinctStatementHaving()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->having("status = 1");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING (SUM(qty) > 10) AND (`status` = 1)", (string)$query);
    }

    public function testSelectDistinctStatementGroupBySimple()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->groupBy("parent_id");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` GROUP BY `parent_id`", (string)$query);
    }

    public function testSelectDistinctStatementGroupBy()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->groupBy("parent_id");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id, `parent_id` HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectDistinctStatementOrderBySimple()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10", 'mysql');
        $query->orderBy("parent_id");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY `parent_id`", (string)$query);
    }

    public function testSelectDistinctStatementGroupByArray()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->groupBy(["test1", "test2", "test3"]);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` GROUP BY `test1`, `test2`, `test3`", (string)$query);
    }

    public function testSelectDistinctStatementOrderBy()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz", 'mysql');
        $query->orderBy("parent_id");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY `parent_id`, xyz", (string)$query);
    }


    public function testSelectDistinctStatementOrderByAsc()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->orderBy("parent_id", Query::ASC);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` ORDER BY `parent_id` ASC", (string)$query);
    }

    public function testSelectDistinctStatementOrderByDesc()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->orderBy("parent_id", Query::DESC);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` ORDER BY `parent_id` DESC", (string)$query);
    }

    public function testSelectDistinctStatementOrderByAppend()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz", 'mysql');
        $query->orderBy("parent_id", Query::APPEND);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz, `parent_id`", (string)$query);
    }

    public function testSelectDistinctStatementOrderByArray()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->orderBy(["name", "description", "checksum"]);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` ORDER BY `name`, `description`, `checksum`", (string)$query);
    }

    public function testSelectDistinctStatementWhereCriteriaEquals()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->where("status", 1);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testSelectDistinctStatementWhereCriteriaGreatEq()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->where('id >= ?', 1);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `id` >= 1", (string)$query);
    }

    public function testSelectDistinctStatementWhereCriteriaOr()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10", 'mysql');
        $query->where('xyz = ? OR abc = ?', [10, 20]);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE (id > 10) AND (`xyz` = 10 OR `abc` = 20)", (string)$query);
    }

    public function testSelectDistinctStatementWhereCriteriaIn()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->where('xyz', ['a', 'b', 'c']);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `xyz` IN ('a', 'b', 'c')", (string)$query);
    }

    public function testSelectDistinctStatementWhereCriteriaBetween()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->where('xyz BETWEEN ? AND ?', [10, 12]);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `xyz` BETWEEN 10 AND 12", (string)$query);
    }

    public function testSelectDistinctStatementWhereCriteriaLikeWildcard()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->where('description LIKE ?%', 'bea');
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `description` LIKE 'bea%'", (string)$query);
    }

    public function testSelectDistinctStatementLimit()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->limit(10);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 10", (string)$query);
    }

    public function testSelectDistinctStatementLimitReplace()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LIMIT 12", 'mysql');
        $query->limit(50, 30);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 50 OFFSET 30", (string)$query);
    }

    public function testSelectDistinctStatementLimitString()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LIMIT 12", 'mysql');
        $query->limit("50 OFFSET 30");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 50 OFFSET 30", (string)$query);
    }

    public function testSelecDistincttStatementPage()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`", 'mysql');
        $query->page(4, 10);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    public function testSelectDistinctStatementPageLimit()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LIMIT 10", 'mysql');
        $query->page(4);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    public function testSelectDistinctStatementPageLimitAgain()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 4, 10", 'mysql');
        $query->page(4);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }


    //-------- INSERT

    public function testInsertStatementReplaceTable()
    {
        $query = new Query("INSERT INTO `test` SET description='abc', type_id=10", 'mysql');
        $query->into("abc");
        $this->assertEquals("INSERT INTO `abc` SET description='abc', type_id=10", (string)$query);
    }

    public function testInsertStatementAddSet()
    {
        $query = new Query("INSERT INTO `test` SET description='abc', type_id=10", 'mysql');
        $query->set("abc=12");
        $this->assertEquals("INSERT INTO `test` SET description='abc', type_id=10, `abc`=12", (string)$query);
    }

    public function testInsertStatementAddValuesString()
    {
        $query = new Query("INSERT INTO `test` VALUES (NULL, 'abc', 10)", 'mysql');
        $query->values("DEFAULT, 'xyz', 12");
        $this->assertEquals("INSERT INTO `test` VALUES (NULL, 'abc', 10), (DEFAULT, 'xyz', 12)", (string)$query);
    }

    public function testInsertStatementAddValuesArray()
    {
        $query = new Query("INSERT INTO `test` VALUES (NULL, 'abc', 10)", 'mysql');
        $query->values([null, 'xyz', 12]);
        $this->assertEquals("INSERT INTO `test` VALUES (NULL, 'abc', 10), (DEFAULT, 'xyz', 12)", (string)$query);
    }

    public function testInsertStatementAddValuesArrayOfArrays()
    {
        $query = new Query("INSERT INTO `test` VALUES (NULL, 'abc', 10)", 'mysql');
        $query->values([[null, 'xyz', 12], ["test", "Test", 1]]);
        $this->assertEquals("INSERT INTO `test` VALUES (NULL, 'abc', 10), (DEFAULT, 'xyz', 12), ('test', 'Test', 1)", (string)$query);
    }

    public function testInsertStatemmentSelect()
    {
        $query = new Query("INSERT INTO `test`", 'mysql');
        $query->set("SELECT FROM `table1`");
        $this->assertEquals("INSERT INTO `test` SELECT FROM `table1`", (string)$query);
    }

    public function testInsertStatementOnDuplicateKeyUpdate()
    {
        $query = new Query("INSERT INTO table (a,b,c) VALUES (1,2,3)", 'mysql');
        $query->onDuplicateKeyUpdate("a");
        $this->assertEquals("INSERT INTO table (a,b,c) VALUES (1,2,3) ON DUPLICATE KEY UPDATE `a` = VALUES(`a`)", (string)$query);
    }

    public function testInsertStatementOnDuplicateKeyUpdatearrayColumnArgument()
    {
        $query = new Query("INSERT INTO table (a,b,c) VALUES (1,2,3)", 'mysql');
        $query->onDuplicateKeyUpdate(["a", "c"]);
        $this->assertEquals("INSERT INTO table (a,b,c) VALUES (1,2,3) ON DUPLICATE KEY UPDATE `a` = VALUES(`a`), `c` = VALUES(`c`)", (string)$query);
    }

    public function testInsertStatementOnDuplicateKeyUpdateArrayKeyValue()
    {
        $query = new Query("INSERT INTO table (a,b,c) VALUES (1,2,3)", 'mysql');
        $query->onDuplicateKeyUpdate(["a" => 15, "c" => 14]);
        $this->assertEquals("INSERT INTO table (a,b,c) VALUES (1,2,3) ON DUPLICATE KEY UPDATE `a` = 15, `c` = 14", (string)$query);
    }

    public function testInsertStatementOnDuplicateKeyUpdateAutomatic()
    {
        $query = new Query("INSERT INTO table (a,b,c) VALUES (1,2,3)", 'mysql');
        $query->onDuplicateKeyUpdate();
        $this->assertEquals("INSERT INTO table (a,b,c) VALUES (1,2,3) ON DUPLICATE KEY UPDATE `a` = VALUES(`a`), `b` = VALUES(`b`), `c` = VALUES(`c`)", (string)$query);
    }

    //-------- UPDATE

    public function testUpdateStatementAddSet()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->set("abc", 12);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10, `abc` = 12", (string)$query);
    }

    public function testUpdateStatementAddSetSimple()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->set("abc=12");
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10, `abc`=12", (string)$query);
    }

    public function testUpdateStatementAddSetExpression()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->set("abc", 'abc + 1', Query::SET_EXPRESSION);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10, `abc` = `abc` + 1", (string)$query);
    }

    public function testUpdateStatementAddSetArray()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE xyz=10", 'mysql');
        $query->set(['abc' => 12, 'def' => "a"]);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10, `abc` = 12, `def` = 'a' WHERE xyz=10", (string)$query);
    }

    public function testUpdateStatementAddSetArrayExpression()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->set(["abc" => 'abc + 1', 'def' => 'NULL'], Query::SET_EXPRESSION);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10, `abc` = `abc` + 1, `def` = NULL", (string)$query);
    }

    public function testUpdateStatementAddSetReplace()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE xyz=10", 'mysql');
        $query->set("abc=12", null, Query::REPLACE);
        $this->assertEquals("UPDATE `test` SET `abc`=12 WHERE xyz=10", (string)$query);
    }

    public function testUpdateStatementReplaceTable()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->table("abc");
        $this->assertEquals("UPDATE `abc` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatementAddTable()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->table("abc", Query::APPEND);
        $this->assertEquals("UPDATE `test` , `abc` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatementInnerJoin()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->innerJoin("abc");
        $this->assertEquals("UPDATE `test` INNER JOIN `abc` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatementInnerJoinOn()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->innerJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("UPDATE `test` INNER JOIN `abc` ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatementLeftJoin()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE xy > 10", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("UPDATE `test` LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10 WHERE xy > 10", (string)$query);
    }

    public function testUpdateStatementLeftJoinAgain()
    {
        $query = new Query("UPDATE `test` LEFT JOIN x ON test.x_id = x.id SET description='abc', type_id=10", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("UPDATE (`test` LEFT JOIN x ON test.x_id = x.id) LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatementLeftJoinPrepend()
    {
        $query = new Query("UPDATE `test` LEFT JOIN x ON test.x_id = x.id SET description='abc', type_id=10", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest", Query::PREPEND);
        $this->assertEquals("UPDATE `abc` LEFT JOIN (`test` LEFT JOIN x ON test.x_id = x.id) ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatementRightJoin()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE xy > 10", 'mysql');
        $query->rightJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("UPDATE `test` RIGHT JOIN `abc` ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10 WHERE xy > 10", (string)$query);
    }

    public function testUpdateStatementWhereSimple()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->where("status = 1");
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE `status` = 1", (string)$query);
    }

    public function testUpdateStatementWhere()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE id > 10", 'mysql');
        $query->where(['status' => 1, 'xyz' => 'abc']);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE (id > 10) AND (`status` = 1 AND `xyz` = 'abc')", (string)$query);
    }

    public function testUpdateStatementWherePrepend()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE id > 10", 'mysql');
        $query->where("status = 1", null, Query::PREPEND);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE (`status` = 1) AND (id > 10)", (string)$query);
    }

    public function testUpdateStatementWhereReplace()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE id > 10", 'mysql');
        $query->where("status = 1", null, Query::REPLACE);
        $query->where("xyz = 1");
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE (`status` = 1) AND (`xyz` = 1)", (string)$query);
    }

    public function testUpdateStatementWhereCriteria()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->where("status", 1);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE `status` = 1", (string)$query);
    }

    public function testUpdateStatementLimit()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10", 'mysql');
        $query->limit(10);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 LIMIT 10", (string)$query);
    }

    //-------- DELETE


    public function testDeleteStatementAddColumn()
    {
        $query = new Query("DELETE FROM `test`", 'mysql');
        $query->column("test.*");
        $this->assertEquals("DELETE `test`.* FROM `test`", (string)$query);
    }

    public function testDeleteStatementReplaceColumn()
    {
        $query = new Query("DELETE `test`.* FROM `test`", 'mysql');
        $query->column("test112.*", Query::REPLACE);
        $this->assertEquals("DELETE `test112`.* FROM `test`", (string)$query);
    }

    public function testDeleteStatementReplaceTable()
    {
        $query = new Query("DELETE FROM `test`", 'mysql');
        $query->from("abc");
        $this->assertEquals("DELETE FROM `abc`", (string)$query);
    }

    public function testDeleteStatementAddTable()
    {
        $query = new Query("DELETE FROM `test`", 'mysql');
        $query->from("abc", Query::APPEND);
        $this->assertEquals("DELETE FROM `test` , `abc`", (string)$query);
    }

    public function testDeleteStatementInnerJoin()
    {
        $query = new Query("DELETE FROM `test`", 'mysql');
        $query->innerJoin("abc");
        $this->assertEquals("DELETE FROM `test` INNER JOIN `abc`", (string)$query);
    }

    public function testDeleteStatementInnerJoinON()
    {
        $query = new Query("DELETE FROM `test`", 'mysql');
        $query->innerJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("DELETE FROM `test` INNER JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testDeleteStatementLeftJoin()
    {
        $query = new Query("DELETE FROM `test`", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("DELETE FROM `test` LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testDeleteStatementLeftJoinAgain()
    {
        $query = new Query("DELETE FROM `test` LEFT JOIN x ON test.x_id = x.id", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("DELETE FROM (`test` LEFT JOIN x ON test.x_id = x.id) LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testDeleteStatementLeftJoinPrepend()
    {
        $query = new Query("DELETE FROM `test` LEFT JOIN x ON test.x_id = x.id", 'mysql');
        $query->leftJoin("abc", "test.id = abc.idTest", Query::PREPEND);
        $this->assertEquals("DELETE FROM `abc` LEFT JOIN (`test` LEFT JOIN x ON test.x_id = x.id) ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testDeleteStatementWhereSimple()
    {
        $query = new Query("DELETE FROM `test`", 'mysql');
        $query->where("status = 1");
        $this->assertEquals("DELETE FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testDeleteStatementWhere()
    {
        $query = new Query("DELETE FROM `test` WHERE id > 10", 'mysql');
        $query->where("status = 1");
        $this->assertEquals("DELETE FROM `test` WHERE (id > 10) AND (`status` = 1)", (string)$query);
    }

    public function testDeleteStatementWherePrepend()
    {
        $query = new Query("DELETE FROM `test` WHERE id > 10", 'mysql');
        $query->where("status = 1", null, Query::PREPEND);
        $this->assertEquals("DELETE FROM `test` WHERE (`status` = 1) AND (id > 10)", (string)$query);
    }

    public function testDeleteStatementWhereReplace()
    {
        $query = new Query("DELETE FROM `test` WHERE id > 10", 'mysql');
        $query->where("status = 1", null, Query::REPLACE);
        $query->where("xyz = 1");
        $this->assertEquals("DELETE FROM `test` WHERE (`status` = 1) AND (`xyz` = 1)", (string)$query);
    }

    public function testDeleteStatementWhereCriteria()
    {
        $query = new Query("DELETE FROM `test`", 'mysql');
        $query->where("status", 1);
        $this->assertEquals("DELETE FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testDeleteStatementLimit()
    {
        $query = new Query("DELETE FROM `test`", 'mysql');
        $query->limit(10);
        $this->assertEquals("DELETE FROM `test` LIMIT 10", (string)$query);
    }

    // -- truncate

    public function testTruncateTable()
    {
        $query = new Query("TRUNCATE TABLE `dates`", 'mysql');
        $query->table("aaa", Query::REPLACE);
        $this->assertEquals("TRUNCATE TABLE `aaa`", (string)$query);
    }
}
