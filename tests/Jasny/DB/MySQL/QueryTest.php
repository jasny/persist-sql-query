<?php

namespace Jasny\DB\MySQL;

//use JsonSchema\Constraints\String;

require_once 'PHPUnit/Framework/TestCase.php';

/**
 * 
 * @package Test
 * @subpackage Query
 */
class QueryTest extends \PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        Query::loadNamedWith(null);
    }

    // ----- SELECT

    public function testSelectStatement_AddColumn()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->column("abc");
        $this->assertEquals("SELECT id, description, `abc` FROM `test`", (string)$query);
    }

    public function testSelectStatement_AddColumn_Array()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->column(array("abc", "def", "ghi"), Query::APPEND);
        $this->assertEquals("SELECT id, description, `abc`, `def`, `ghi` FROM `test`", (string)$query);
    }

    public function testSelectStatement_AddColumn_Prepend()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->column("abc", Query::PREPEND);
        $this->assertEquals("SELECT `abc`, id, description FROM `test`", (string)$query);
    }

    public function testSelectStatement_AddColumn_Replace()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->column("abc", Query::REPLACE);
        $this->assertEquals("SELECT `abc` FROM `test`", (string)$query);
    }

    public function testSelectStatement_ReplaceTable()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE xy > 10");
        $query->from("abc");
        $this->assertEquals("SELECT id, description FROM `abc` WHERE xy > 10", (string)$query);
    }

    public function testSelectStatement_AddTable()
    {
        // Removing the extra space between table and comma, would make the code slower.

        $query = new Query("SELECT id, description FROM `test` WHERE xy > 10");
        $query->from("abc", Query::APPEND);
        $this->assertEquals("SELECT id, description FROM `test` , `abc` WHERE xy > 10", (string)$query);
    }

    public function testSelectStatement_InnerJoin()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->innerJoin("abc");
        $this->assertEquals("SELECT id, description FROM `test` INNER JOIN `abc`", (string)$query);
    }

    public function testSelectStatement_InnerJoin_Replace()
    {
        $this->markTestSkipped("Supposed defect of not implemented");
        $query = new Query("SELECT id, description FROM `test` INNER JOIN `abc`");
        $query->innerJoin("xyz", null, Query::REPLACE);
        $this->assertEquals("SELECT id, description FROM `test` INNER JOIN `xyz`", (string)$query);
    }

    public function testSelectStatement_InnerJoin_On()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->innerJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT id, description FROM `test` INNER JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectStatement_LeftJoin()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE xy > 10");
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT id, description FROM `test` LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest` WHERE xy > 10", (string)$query);
    }

    public function testSelectStatement_LeftJoin_Again()
    {
        $query = new Query("SELECT id, description FROM `test` LEFT JOIN x ON test.x_id = x.id");
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT id, description FROM (`test` LEFT JOIN x ON test.x_id = x.id) LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectStatement_LeftJoin_Prepend()
    {
        $query = new Query("SELECT id, description FROM `test` LEFT JOIN x ON test.x_id = x.id");
        $query->leftJoin("abc", "test.id = abc.idTest", Query::PREPEND);
        $this->assertEquals("SELECT id, description FROM `abc` LEFT JOIN (`test` LEFT JOIN x ON test.x_id = x.id) ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectStatement_RightJoin()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE xy > 10");
        $query->rightJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT id, description FROM `test` RIGHT JOIN `abc` ON `test`.`id` = `abc`.`idTest` WHERE xy > 10", (string)$query);
    }

    public function testSelectStatement_Where_Simple()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->where("status = 1");
        $this->assertEquals("SELECT id, description FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testSelectStatement_Where()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->where("status = 1");
        $this->assertEquals("SELECT id, description FROM `test` WHERE (id > 10) AND (`status` = 1) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectStatement_Where_Prepend()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->where("status = 1", null, Query::PREPEND);
        $this->assertEquals("SELECT id, description FROM `test` WHERE (`status` = 1) AND (id > 10) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectStatement_Where_Replace()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->where("status = 1", null, Query::REPLACE);
        $query->where("xyz = 1");
        $this->assertEquals("SELECT id, description FROM `test` WHERE (`status` = 1) AND (`xyz` = 1) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectStatement_Having()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->having("status = 1");
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING (SUM(qty) > 10) AND (`status` = 1)", (string)$query);
    }

    public function testSelectStatement_GroupBy_Simple()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->groupBy("parent_id");
        $this->assertEquals("SELECT id, description FROM `test` GROUP BY `parent_id`", (string)$query);
    }

    public function testSelectStatement_GroupBy()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->groupBy("parent_id");
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id, `parent_id` HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectStatement_OrderBy_Simple()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->orderBy("parent_id");
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY `parent_id`", (string)$query);
    }

    public function testSelectStatement_OrderBy_Array()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->groupBy(array("test1", "test2", "test3"));
        $this->assertEquals("SELECT id, description FROM `test` GROUP BY `test1`, `test2`, `test3`", (string)$query);
    }

    public function testSelectStatement_OrderBy()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz");
        $query->orderBy("parent_id");
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY `parent_id`, xyz", (string)$query);
    }


    public function testSelectStatement_OrderBy_Asc()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->orderBy("parent_id", Query::ASC);
        $this->assertEquals("SELECT id, description FROM `test` ORDER BY `parent_id` ASC", (string)$query);
    }

    public function testSelectStatement_OrderBy_Desc()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->orderBy("parent_id", Query::DESC);
        $this->assertEquals("SELECT id, description FROM `test` ORDER BY `parent_id` DESC", (string)$query);
    }

    public function testSelectStatement_OrderBy_Append()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz");
        $query->orderBy("parent_id", Query::APPEND);
        $this->assertEquals("SELECT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz, `parent_id`", (string)$query);
    }

    public function testSelectStatement_Order_By_Array()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->orderBy(array("name","description","checksum"));
        $this->assertEquals("SELECT id, description FROM `test` ORDER BY `name`, `description`, `checksum`", (string)$query);
    }

    public function testSelectStatement_WhereCriteria_Equals()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->where("status", 1);
        $this->assertEquals("SELECT id, description FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testSelectStatement_WhereCriteria_GreatEq()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->where('id >= ?', 1);
        $this->assertEquals("SELECT id, description FROM `test` WHERE `id` >= 1", (string)$query);
    }

    public function testSelectStatement_WhereCriteria_Or()
    {
        $query = new Query("SELECT id, description FROM `test` WHERE id > 10");
        $query->where('xyz = ? OR abc = ?', array(10, 20));
        $this->assertEquals("SELECT id, description FROM `test` WHERE (id > 10) AND (`xyz` = 10 OR `abc` = 20)", (string)$query);
    }

    public function testSelectStatement_WhereCriteria_In()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->where('xyz', array('a', 'b', 'c'));
        $this->assertEquals("SELECT id, description FROM `test` WHERE `xyz` IN (\"a\", \"b\", \"c\")", (string)$query);
    }

    public function testSelectStatement_WhereCriteria_Between()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->where('xyz BETWEEN ? AND ?', array(10, 12));
        $this->assertEquals("SELECT id, description FROM `test` WHERE `xyz` BETWEEN 10 AND 12", (string)$query);
    }

    public function testSelectStatement_WhereCriteria_LikeWildcard()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->where('description LIKE ?%', 'bea');
        $this->assertEquals("SELECT id, description FROM `test` WHERE `description` LIKE \"bea%\"", (string)$query);
    }

    public function testSelectStatement_Limit()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->limit(10);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10", (string)$query);
    }

    public function testSelectStatement_Limit_Replace()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 12");
        $query->limit(50, 30);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 50 OFFSET 30", (string)$query);
    }

    public function testSelectStatement_Limit_String()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 12");
        $query->limit("50 OFFSET 30");
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 50 OFFSET 30", (string)$query);
    }

    public function testSelectStatement_Page()
    {
        $query = new Query("SELECT id, description FROM `test`");
        $query->page(4, 10);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    public function testSelectStatement_Page_Limit()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 10");
        $query->page(4);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    public function testSelectStatement_Page_Limit_Again()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 4, 10");
        $query->page(4);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    // ---------- Select DISTINCT

    public function testSelectDistinctStatement_AddColumn()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->column("abc");
        $this->assertEquals("SELECT DISTINCT id, description, `abc` FROM `test`", (string)$query);
    }

    public function testSelectDistinctStatement_AddColumn_Array()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->column(array("abc", "def", "ghi"), Query::APPEND);
        $this->assertEquals("SELECT DISTINCT id, description, `abc`, `def`, `ghi` FROM `test`", (string)$query);
    }

    public function testSelectDistinctStatement_AddColumn_Prepend()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->column("abc", Query::PREPEND);
        $this->assertEquals("SELECT DISTINCT `abc`, id, description FROM `test`", (string)$query);
    }

    public function testSelectDistinctStatement_AddColumn_Replace()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->column("abc", Query::REPLACE);
        $this->assertEquals("SELECT DISTINCT `abc` FROM `test`", (string)$query);
    }

    public function testSelectDistinctStatement_ReplaceTable()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE xy > 10");
        $query->from("abc");
        $this->assertEquals("SELECT DISTINCT id, description FROM `abc` WHERE xy > 10", (string)$query);
    }

    public function testSelectDistinctStatement_AddTable()
    {
        // Removing the extra space between table and comma, would make the code slower.

        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE xy > 10");
        $query->from("abc", Query::APPEND);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` , `abc` WHERE xy > 10", (string)$query);
    }

    public function testSelectDistinctStatement_InnerJoin()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->innerJoin("abc");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` INNER JOIN `abc`", (string)$query);
    }

    public function testSelectDistinctStatement_InnerJoin_On()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->innerJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` INNER JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectDistinctStatement_LeftJoin()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE xy > 10");
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest` WHERE xy > 10", (string)$query);
    }

    public function testSelectDistinctStatement_LeftJoin_Again()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LEFT JOIN x ON test.x_id = x.id");
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT DISTINCT id, description FROM (`test` LEFT JOIN x ON test.x_id = x.id) LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectDistinctStatement_LeftJoin_Prepend()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LEFT JOIN x ON test.x_id = x.id");
        $query->leftJoin("abc", "test.id = abc.idTest", Query::PREPEND);
        $this->assertEquals("SELECT DISTINCT id, description FROM `abc` LEFT JOIN (`test` LEFT JOIN x ON test.x_id = x.id) ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testSelectDistinctStatement_RightJoin()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE xy > 10");
        $query->rightJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` RIGHT JOIN `abc` ON `test`.`id` = `abc`.`idTest` WHERE xy > 10", (string)$query);
    }

    public function testSelectDistinctStatement_Where_Simple()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->where("status = 1");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testSelectDistinctStatement_Where()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->where("status = 1");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE (id > 10) AND (`status` = 1) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectDistinctStatement_Where_Prepend()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->where("status = 1", null, Query::PREPEND);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE (`status` = 1) AND (id > 10) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectDistinctStatement_Where_Replace()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->where("status = 1", null, Query::REPLACE);
        $query->where("xyz = 1");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE (`status` = 1) AND (`xyz` = 1) GROUP BY type_id HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectDistinctStatement_Having()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->having("status = 1");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING (SUM(qty) > 10) AND (`status` = 1)", (string)$query);
    }

    public function testSelectDistinctStatement_GroupBy_Simple()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->groupBy("parent_id");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` GROUP BY `parent_id`", (string)$query);
    }

    public function testSelectDistinctStatement_GroupBy()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->groupBy("parent_id");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id, `parent_id` HAVING SUM(qty) > 10", (string)$query);
    }

    public function testSelectDistinctStatement_OrderBy_Simple()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10");
        $query->orderBy("parent_id");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY `parent_id`", (string)$query);
    }

    public function testSelectDistinctStatement_OrderBy_Array()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->groupBy(array("test1", "test2", "test3"));
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` GROUP BY `test1`, `test2`, `test3`", (string)$query);
    }

    public function testSelectDistinctStatement_OrderBy()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz");
        $query->orderBy("parent_id");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY `parent_id`, xyz", (string)$query);
    }


    public function testSelectDistinctStatement_OrderBy_Asc()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->orderBy("parent_id", Query::ASC);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` ORDER BY `parent_id` ASC", (string)$query);
    }

    public function testSelectDistinctStatement_OrderBy_Desc()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->orderBy("parent_id", Query::DESC);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` ORDER BY `parent_id` DESC", (string)$query);
    }

    public function testSelectDistinctStatement_OrderBy_Append()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz");
        $query->orderBy("parent_id", Query::APPEND);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE id > 10 GROUP BY type_id HAVING SUM(qty) > 10 ORDER BY xyz, `parent_id`", (string)$query);
    }

    public function testSelectDistinctStatement_Order_By_Array()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->orderBy(array("name","description","checksum"));
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` ORDER BY `name`, `description`, `checksum`", (string)$query);
    }

    public function testSelectDistinctStatement_WhereCriteria_Equals()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->where("status", 1);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testSelectDistinctStatement_WhereCriteria_GreatEq()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->where('id >= ?', 1);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `id` >= 1", (string)$query);
    }

    public function testSelectDistinctStatement_WhereCriteria_Or()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` WHERE id > 10");
        $query->where('xyz = ? OR abc = ?', array(10, 20));
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE (id > 10) AND (`xyz` = 10 OR `abc` = 20)", (string)$query);
    }

    public function testSelectDistinctStatement_WhereCriteria_In()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->where('xyz', array('a', 'b', 'c'));
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `xyz` IN (\"a\", \"b\", \"c\")", (string)$query);
    }

    public function testSelectDistinctStatement_WhereCriteria_Between()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->where('xyz BETWEEN ? AND ?', array(10, 12));
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `xyz` BETWEEN 10 AND 12", (string)$query);
    }

    public function testSelectDistinctStatement_WhereCriteria_LikeWildcard()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->where('description LIKE ?%', 'bea');
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` WHERE `description` LIKE \"bea%\"", (string)$query);
    }

    public function testSelectDistinctStatement_Limit()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->limit(10);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 10", (string)$query);
    }

    public function testSelectDistinctStatement_Limit_Replace()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LIMIT 12");
        $query->limit(50, 30);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 50 OFFSET 30", (string)$query);
    }

    public function testSelectDistinctStatement_Limit_String()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LIMIT 12");
        $query->limit("50 OFFSET 30");
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 50 OFFSET 30", (string)$query);
    }

    public function testSelecDistincttStatement_Page()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test`");
        $query->page(4, 10);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    public function testSelectDistinctStatement_Page_Limit()
    {
        $query = new Query("SELECT DISTINCT id, description FROM `test` LIMIT 10");
        $query->page(4);
        $this->assertEquals("SELECT DISTINCT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }

    public function testSelectDistinctStatement_Page_Limit_Again()
    {
        $query = new Query("SELECT id, description FROM `test` LIMIT 4, 10");
        $query->page(4);
        $this->assertEquals("SELECT id, description FROM `test` LIMIT 10 OFFSET 30", (string)$query);
    }


    //-------- INSERT

    public function testInsertStatement_ReplaceTable()
    {
        $query = new Query("INSERT INTO `test` SET description='abc', type_id=10");
        $query->into("abc");
        $this->assertEquals("INSERT INTO `abc` SET description='abc', type_id=10", (string)$query);
    }

    public function testInsertStatement_AddSet()
    {
        $query = new Query("INSERT INTO `test` SET description='abc', type_id=10");
        $query->set("abc=12");
        $this->assertEquals("INSERT INTO `test` SET description='abc', type_id=10, `abc`=12", (string)$query);
    }

    public function testInsertStatement_AddValues_String()
    {
        $query = new Query("INSERT INTO `test` VALUES (NULL, 'abc', 10)");
        $query->values('DEFAULT, "xyz", 12');
        $this->assertEquals("INSERT INTO `test` VALUES (NULL, 'abc', 10), (DEFAULT, \"xyz\", 12)", (string)$query);
    }

    public function testInsertStatement_AddValues_ArrayOfArrays()
    {
        $this->markTestSkipped("Defect");
        $query = new Query("INSERT INTO `test` VALUES (NULL, 'abc', 10)");
        $query->values(array(array(null, 'xyz', 12)),array("test","Test"), Query::REPLACE);
        echo $query;
        $this->assertEquals("INSERT INTO `test` VALUES (NULL, 'abc', 10), (\"xyz\")", (string)$query);
    }

    public function testInsertStatement_AddValues_Array()
    {
        $query = new Query("INSERT INTO `test` VALUES (NULL, 'abc', 10)");
        $query->values(array(null, 'xyz', 12));
        $this->assertEquals("INSERT INTO `test` VALUES (NULL, 'abc', 10), (DEFAULT, \"xyz\", 12)", (string)$query);
    }


    public function testInsertStatemment_Select()
    {
        $query = new Query("INSERT INTO `test`");
        $query->set("SELECT FROM `table1`");
        $this->assertEquals("INSERT INTO `test` SELECT FROM `table1`", (string)$query);
    }

    public function testInsertStatement_OnDuplicateKeyUpdate()
    {
        $query = new Query("INSERT INTO table (a,b,c) VALUES (1,2,3)");
        $query->onDuplicateKeyUpdate("a");
        $this->assertEquals("INSERT INTO table (a,b,c) VALUES (1,2,3) ON DUPLICATE KEY UPDATE `a` = VALUES(`a`)", (string)$query);
    }

    public function testInsertStatement_OnDuplicateKeyUpdate_arrayColumnArgument()
    {
        $query = new Query("INSERT INTO table (a,b,c) VALUES (1,2,3)");
        $query->onDuplicateKeyUpdate(array("a","c"));
        $this->assertEquals("INSERT INTO table (a,b,c) VALUES (1,2,3) ON DUPLICATE KEY UPDATE `a` = VALUES(`a`), `c` = VALUES(`c`)", (string)$query);
    }

    public function testInsertStatement_OnDuplicateKeyUpdate_arrayKeyValue()
    {
        $query = new Query("INSERT INTO table (a,b,c) VALUES (1,2,3)");
        $query->onDuplicateKeyUpdate(array("a" => 15,"c" => 14));
        $this->assertEquals("INSERT INTO table (a,b,c) VALUES (1,2,3) ON DUPLICATE KEY UPDATE `a` = 15, `c` = 14", (string)$query);
    }

    //-------- UPDATE

    public function testUpdateStatement_AddSet()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10");
        $query->set("abc", 12);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10, `abc` = 12", (string)$query);
    }

    public function testUpdateStatement_AddSet_Simple()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10");
        $query->set("abc=12");
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10, `abc`=12", (string)$query);
    }

    public function testUpdateStatement_AddSet_Array()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE xyz=10");
        $query->set(array('abc' => 12, 'def' => "a"));
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10, `abc` = 12, `def` = \"a\" WHERE xyz=10", (string)$query);
    }

    public function testUpdateStatement_AddSet_Replace()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE xyz=10");
        $query->set("abc=12", null, Query::REPLACE);
        $this->assertEquals("UPDATE `test` SET `abc`=12 WHERE xyz=10", (string)$query);
    }

    public function testUpdateStatement_ReplaceTable()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10");
        $query->table("abc");
        $this->assertEquals("UPDATE `abc` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatement_AddTable()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10");
        $query->table("abc", Query::APPEND);
        $this->assertEquals("UPDATE `test` , `abc` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatement_InnerJoin()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10");
        $query->innerJoin("abc");
        $this->assertEquals("UPDATE `test` INNER JOIN `abc` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatement_InnerJoin_On()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10");
        $query->innerJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("UPDATE `test` INNER JOIN `abc` ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatement_LeftJoin()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE xy > 10");
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("UPDATE `test` LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10 WHERE xy > 10", (string)$query);
    }

    public function testUpdateStatement_LeftJoin_Again()
    {
        $query = new Query("UPDATE `test` LEFT JOIN x ON test.x_id = x.id SET description='abc', type_id=10");
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("UPDATE (`test` LEFT JOIN x ON test.x_id = x.id) LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatement_LeftJoin_Prepend()
    {
        $query = new Query("UPDATE `test` LEFT JOIN x ON test.x_id = x.id SET description='abc', type_id=10");
        $query->leftJoin("abc", "test.id = abc.idTest", Query::PREPEND);
        $this->assertEquals("UPDATE `abc` LEFT JOIN (`test` LEFT JOIN x ON test.x_id = x.id) ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10", (string)$query);
    }

    public function testUpdateStatement_RightJoin()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE xy > 10");
        $query->rightJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("UPDATE `test` RIGHT JOIN `abc` ON `test`.`id` = `abc`.`idTest` SET description='abc', type_id=10 WHERE xy > 10", (string)$query);
    }

    public function testUpdateStatement_Where_Simple()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10");
        $query->where("status = 1");
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE `status` = 1", (string)$query);
    }

    public function testUpdateStatement_Where()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE id > 10");
        $query->where(array('status' => 1, 'xyz' => 'abc'));
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE (id > 10) AND (`status` = 1 AND `xyz` = \"abc\")", (string)$query);
    }

    public function testUpdateStatement_Where_Prepend()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE id > 10");
        $query->where("status = 1", null, Query::PREPEND);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE (`status` = 1) AND (id > 10)", (string)$query);
    }

    public function testUpdateStatement_Where_Replace()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10 WHERE id > 10");
        $query->where("status = 1", null, Query::REPLACE);
        $query->where("xyz = 1");
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE (`status` = 1) AND (`xyz` = 1)", (string)$query);
    }

    public function testUpdateStatement_WhereCriteria()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10");
        $query->where("status", 1);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 WHERE `status` = 1", (string)$query);
    }

    public function testUpdateStatement_Limit()
    {
        $query = new Query("UPDATE `test` SET description='abc', type_id=10");
        $query->limit(10);
        $this->assertEquals("UPDATE `test` SET description='abc', type_id=10 LIMIT 10", (string)$query);
    }

    //-------- DELETE


    public function testDeleteStatement_AddColumn()
    {
        $query = new Query("DELETE FROM `test`");
        $query->column("test.*");
        $this->assertEquals("DELETE `test`.* FROM `test`", (string)$query);
    }

    public function testDeleteStatement_ReplaceColumn()
    {
        $query = new Query("DELETE `test`.* FROM `test`");
        $query->column("test112.*", Query::REPLACE);
        $this->assertEquals("DELETE `test112`.* FROM `test`", (string)$query);
    }

    public function testDeleteStatement_ReplaceTable()
    {
        $query = new Query("DELETE FROM `test`");
        $query->from("abc");
        $this->assertEquals("DELETE FROM `abc`", (string)$query);
    }

    public function testDeleteStatement_AddTable()
    {
        $query = new Query("DELETE FROM `test`");
        $query->from("abc", Query::APPEND);
        $this->assertEquals("DELETE FROM `test` , `abc`", (string)$query);
    }

    public function testDeleteStatement_InnerJoin()
    {
        $query = new Query("DELETE FROM `test`");
        $query->innerJoin("abc");
        $this->assertEquals("DELETE FROM `test` INNER JOIN `abc`", (string)$query);
    }

    public function testDeleteStatement_InnerJoin_ON()
    {
        $query = new Query("DELETE FROM `test`");
        $query->innerJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("DELETE FROM `test` INNER JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testDeleteStatement_LeftJoin()
    {
        $query = new Query("DELETE FROM `test`");
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("DELETE FROM `test` LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testDeleteStatement_LeftJoin_Again()
    {
        $query = new Query("DELETE FROM `test` LEFT JOIN x ON test.x_id = x.id");
        $query->leftJoin("abc", "test.id = abc.idTest");
        $this->assertEquals("DELETE FROM (`test` LEFT JOIN x ON test.x_id = x.id) LEFT JOIN `abc` ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testDeleteStatement_LeftJoin_Prepend()
    {
        $query = new Query("DELETE FROM `test` LEFT JOIN x ON test.x_id = x.id");
        $query->leftJoin("abc", "test.id = abc.idTest", Query::PREPEND);
        $this->assertEquals("DELETE FROM `abc` LEFT JOIN (`test` LEFT JOIN x ON test.x_id = x.id) ON `test`.`id` = `abc`.`idTest`", (string)$query);
    }

    public function testDeleteStatement_Where_Simple()
    {
        $query = new Query("DELETE FROM `test`");
        $query->where("status = 1");
        $this->assertEquals("DELETE FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testDeleteStatement_Where()
    {
        $query = new Query("DELETE FROM `test` WHERE id > 10");
        $query->where("status = 1");
        $this->assertEquals("DELETE FROM `test` WHERE (id > 10) AND (`status` = 1)", (string)$query);
    }

    public function testDeleteStatement_Where_Prepend()
    {
        $query = new Query("DELETE FROM `test` WHERE id > 10");
        $query->where("status = 1", null, Query::PREPEND);
        $this->assertEquals("DELETE FROM `test` WHERE (`status` = 1) AND (id > 10)", (string)$query);
    }

    public function testDeleteStatement_Where_Replace()
    {
        $query = new Query("DELETE FROM `test` WHERE id > 10");
        $query->where("status = 1", null, Query::REPLACE);
        $query->where("xyz = 1");
        $this->assertEquals("DELETE FROM `test` WHERE (`status` = 1) AND (`xyz` = 1)", (string)$query);
    }

    public function testDeleteStatement_WhereCriteria()
    {
        $query = new Query("DELETE FROM `test`");
        $query->where("status", 1);
        $this->assertEquals("DELETE FROM `test` WHERE `status` = 1", (string)$query);
    }

    public function testDeleteStatement_Limit()
    {
        $query = new Query("DELETE FROM `test`");
        $query->limit(10);
        $this->assertEquals("DELETE FROM `test` LIMIT 10", (string)$query);
    }

    // -- truncate

    public function testTruncateTable()
    {
        $this->markTestSkipped("Defect");
        $query = new Query("TRUNCATE TABLE `dates`");
        $query->table("aaa");
        $this->assertEquals("TRUNCATE TABLE `aaa`", (string)$query);
    }

    public function testNamed()
    {
        Query::loadNamedWith(function($name) {
            return "SELECT * FROM $name";
        });

        $this->assertEquals("SELECT * FROM foo", (string)Query::named('foo'));
    }

    public function testNamed_Fail()
    {
        $err = "Unabled to load named queries: first tell how using Query::loadNamedWith()";
        $this->setExpectedException('Exception', $err);

        Query::named('foo');
    }
}
