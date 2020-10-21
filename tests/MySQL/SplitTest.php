<?php /** @noinspection PhpCSValidationInspection */

declare(strict_types=1);

namespace Persist\Tests\SQL\Query\MySQL;

use Persist\SQL\Query\Query;
use Persist\SQL\Query\QueryBuildException;
use Persist\SQL\Query\UnsupportedQueryException;
use PHPUnit\Framework\TestCase;

/**
 * Extract information from MySQL query.
 */
class SplitTest extends TestCase
{
    public function testGetQueryTypeSelect()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $this->assertEquals('SELECT', $query->getType());
    }

    public function testGetQueryTypeSelectWord()
    {
        $query = new Query("SELECT", 'mysql');
        $this->assertEquals('SELECT', $query->getType());
    }

    public function testGetQueryTypeSelectLowerCase()
    {
        $query = new Query("select id, description from `test`", 'mysql');
        $this->assertEquals('SELECT', $query->getType());
    }

    public function testGetQueryTypeSelectSpaces()
    {
        $query = new Query("\n\t\n  SELECT id, description FROM `test`", 'mysql');
        $this->assertEquals('SELECT', $query->getType());
    }

    public function testGetQueryTypeInsert()
    {
        $query = new Query("INSERT INTO `test` SELECT 10", 'mysql');
        $this->assertEquals('INSERT', $query->getType());
    }

    public function testGetQueryTypeReplace()
    {
        $query = new Query("REPLACE INTO `test` VALUES (10, 'UPDATE')", 'mysql');
        $this->assertEquals('REPLACE', $query->getType());
    }

    public function testGetQueryTypeDelete()
    {
        $query = new Query("DELETE FROM `test` WHERE `select`=10", 'mysql');
        $this->assertEquals('DELETE', $query->getType());
    }

    public function testGetQueryTypeTruncate()
    {
        $query = new Query("TRUNCATE `test`", 'mysql');
        $this->assertEquals('TRUNCATE', $query->getType());
    }

    public function testGetQueryTypeAlterTable()
    {
        $query = new Query("ALTER TABLE `test`", 'mysql');
        $this->assertEquals('ALTER TABLE', $query->getType());
    }

    public function testGetQueryTypeAlterViewSpaces()
    {
        $query = new Query("ALTER\n\t\tVIEW `test`", 'mysql');
        $this->assertEquals('ALTER VIEW', $query->getType());
    }

    public function testGetQueryTypeAlterUnknown()
    {
        $query = new Query("ALTER test set abc", 'mysql');
        $this->assertNull($query->getType());
    }

    public function testGetQueryTypeSet()
    {
        $query = new Query("SET @select=10", 'mysql');
        $this->assertEquals('SET', $query->getType());
    }

    public function testGetQueryTypeBegin()
    {
        $query = new Query("BEGIN", 'mysql');
        $this->assertEquals('BEGIN', $query->getType());
    }

    public function testGetQueryTypeLoadDataInfile()
    {
        $query = new Query("LOAD DATA INFILE", 'mysql');
        $this->assertEquals('LOAD DATA INFILE', $query->getType());
    }

    public function testGetQueryTypeComment()
    {
        $query = new Query("-- SELECT `test`", 'mysql');
        $this->assertNull($query->getType());
    }

    public function testGetQueryTypeUnknown()
    {
        $query = new Query("something", 'mysql');
        $this->assertNull($query->getType());
    }

    //--------

    public function testGetSubqueryInWhere()
    {
        $query = new Query("SELECT * FROM relation WHERE id IN (SELECT relation_id FROM team) AND status = 1", 'mysql');

        $this->assertEquals("SELECT relation_id FROM team", (string)$query->getSubquery(1));
    }

    public function testGetSubqueryInJoin()
    {
        $query = new Query("SELECT * FROM relation LEFT JOIN (SELECT relation_id, COUNT(*) FROM contactperson) AS con_cnt ON relation.id = con_cnt.relation_id WHERE id IN (SELECT relation_id FROM team STRAIGHT JOIN (SELECT y, COUNT(x) FROM xy GROUP BY y) AS xy) AND status = 1", 'mysql');

        $this->assertEquals("SELECT relation_id, COUNT(*) FROM contactperson", (string)$query->getSubquery(1));

        $this->assertEquals(
            "SELECT relation_id FROM team STRAIGHT JOIN (SELECT y, COUNT(x) FROM xy GROUP BY y) AS xy",
            (string)$query->getSubquery(2)
        );

        $this->assertEquals(
            "SELECT y, COUNT(x) FROM xy GROUP BY y",
            (string)$query->getSubquery(2)->getSubquery(1)
        );
    }

    public function testGetSubqueryFromInsert()
    {
        $query = new Query("INSERT INTO relation_active SELECT * FROM relation WHERE status = 1", 'mysql');
        $this->assertEquals("SELECT * FROM relation WHERE status = 1", (string)$query->getSubquery());
    }

    public function testGetSubqueriesFromInsertAndInWhere()
    {
        $query = new Query("INSERT INTO relation_active SELECT * FROM relation WHERE id IN (SELECT relation_id FROM team) AND status = 1");

        $this->assertEquals(
            "SELECT * FROM relation WHERE id IN (SELECT relation_id FROM team) AND status = 1",
            (string)$query->getSubquery()
        );

        $this->assertEquals("SELECT relation_id FROM team", (string)$query->getSubquery()->getSubquery());
    }

    // ----


    public function testGetPartsOfSelect()
    {
        $query = new Query("SELECT", 'mysql');
        $this->assertEquals(
            [
                'select' => '',
                'columns' => '',
                'from' => '',
                'where' => '',
                'group by' => '',
                'having' => '',
                'order by' => '',
                'limit' => '',
                'options' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfSelectSimple()
    {
        $query = new Query("SELECT id, description FROM `test`", 'mysql');
        $this->assertEquals(
            [
                'select' => '',
                'columns' => 'id, description',
                'from' => '`test`',
                'where' => '',
                'group by' => '',
                'having' => '',
                'order by' => '',
                'limit' => '',
                'options' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfSelectAdvanced()
    {
        $query = new Query("SELECT DISTINCTROW id, description, CONCAT(name, ' from ', city) AS `tman`, ` ORDER BY` as `order`, \"\" AS nothing FROM `test` INNER JOIN abc ON test.id = abc.id WHERE test.x = 'SELECT A FROM B WHERE C ORDER BY D GROUP BY E HAVING X PROCEDURE Y LOCK IN SHARE MODE' GROUP BY my_dd HAVING COUNT(1+3+xyz) < 100 LIMIT 15, 30 FOR UPDATE", 'mysql');

        $this->assertEquals(
            [
                'select' => 'DISTINCTROW',
                'columns' => "id, description, CONCAT(name, ' from ', city) AS `tman`, ` ORDER BY` as `order`, \"\" AS nothing",
                'from' => "`test` INNER JOIN abc ON test.id = abc.id",
                'where' => "test.x = 'SELECT A FROM B WHERE C ORDER BY D GROUP BY E HAVING X PROCEDURE Y LOCK IN SHARE MODE'",
                'group by' => "my_dd",
                'having' => "COUNT(1+3+xyz) < 100",
                'order by' => '',
                'limit' => "15, 30",
                'options' => "FOR UPDATE"
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfSelectSubquery()
    {
        $query = new Query("SELECT id, description, VALUES(SELECT id, desc FROM subt WHERE status='1' CASCADE ON PARENT id = relatie_id) AS subs FROM `test` INNER JOIN (SELECT * FROM abc WHERE i = 1 GROUP BY x) AS abc WHERE abc.x IN (1,2,3,6,7) AND qq!='(SELECT)' ORDER BY abx.dd", 'mysql');

        $this->assertEquals(
            [
                'select' => '',
                'columns' => "id, description, VALUES(SELECT id, desc FROM subt WHERE status='1' CASCADE ON PARENT id = relatie_id) AS subs",
                'from' => "`test` INNER JOIN (SELECT * FROM abc WHERE i = 1 GROUP BY x) AS abc",
                'where' => "abc.x IN (1,2,3,6,7) AND qq!='(SELECT)'",
                'group by' => '',
                'having' => '',
                'order by' => 'abx.dd',
                'limit' => '',
                'options' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfSelectSubqueryMadness()
    {
        $query = new Query("SELECT id, description, VALUES(SELECT id, desc FROM subt1 INNER JOIN (SELECT id, p_id, desc FROM subt2 INNER JOIN (SELECT id, p_id, myfunct(a, b, c) FROM subt3 WHERE x = 10) AS subt3 ON subt2.id = subt3.p_id) AS subt2 ON subt1.id = subt2.p_id WHERE status='1' CASCADE ON PARENT id = relatie_id) AS subs FROM `test` INNER JOIN (SELECT * FROM abc INNER JOIN (SELECT id, p_id, desc FROM subt2 INNER JOIN (SELECT id, p_id, myfunct(a, b, c) FROM subt3 WHERE x = 10) AS subt3 ON subt2.id = subt3.p_id) AS subt2 ON abc.id = subt2.p_id WHERE i = 1 GROUP BY x) AS abc WHERE abc.x IN (1,2,3,6,7) AND qq!='(SELECT)' AND x_id IN (SELECT id FROM x) ORDER BY abx.dd LIMIT 10", 'mysql');

        $this->assertEquals(
            [
                'select' => '',
                'columns' => "id, description, VALUES(SELECT id, desc FROM subt1 INNER JOIN (SELECT id, p_id, desc FROM subt2 INNER JOIN (SELECT id, p_id, myfunct(a, b, c) FROM subt3 WHERE x = 10) AS subt3 ON subt2.id = subt3.p_id) AS subt2 ON subt1.id = subt2.p_id WHERE status='1' CASCADE ON PARENT id = relatie_id) AS subs",
                'from' => "`test` INNER JOIN (SELECT * FROM abc INNER JOIN (SELECT id, p_id, desc FROM subt2 INNER JOIN (SELECT id, p_id, myfunct(a, b, c) FROM subt3 WHERE x = 10) AS subt3 ON subt2.id = subt3.p_id) AS subt2 ON abc.id = subt2.p_id WHERE i = 1 GROUP BY x) AS abc",
                'where' => "abc.x IN (1,2,3,6,7) AND qq!='(SELECT)' AND x_id IN (SELECT id FROM x)",
                'group by' => '',
                'having' => '',
                'order by' => 'abx.dd',
                'limit' => '10',
                'options' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfSelectSemicolon()
    {
        $query = new Query("SELECT id, description FROM `test`; Please ignore this", 'mysql');
        $this->assertEquals(
            [
                'select' => '',
                'columns' => 'id, description',
                'from' => '`test`',
                'where' => '',
                'group by' => '',
                'having' => '',
                'order by' => '',
                'limit' => '',
                'options' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfInsert()
    {
        $query = new Query("INSERT", 'mysql');

        $this->assertEquals(
            [
                'insert' => '',
                'into' => '',
                'columns' => '',
                'set' => '',
                'values' => '',
                'query' => '',
                'on duplicate key update' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfInsertValuesSimple()
    {
        $query = new Query("INSERT INTO `test` VALUES (NULL, 'abc')", 'mysql');

        $this->assertEquals(
            [
                'insert' => '',
                'into' => '`test`',
                'columns' => '',
                'set' => '',
                'values' => "(NULL, 'abc')",
                'query' => '',
                'on duplicate key update' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfReplaceValuesSimple()
    {
        $query = new Query("REPLACE INTO `test` VALUES (NULL, 'abc')", 'mysql');

        $this->assertEquals(
            [
                'replace' => '',
                'into' => '`test`',
                'columns' => '',
                'set' => '',
                'values' => "(NULL, 'abc')",
                'query' => '',
                'on duplicate key update' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfInsertValuesColumns()
    {
        $query = new Query("INSERT INTO `test` (`id`, description, `values`) VALUES (NULL, 'abc', 10)", 'mysql');

        $this->assertEquals(
            [
                'insert' => '',
                'into' => '`test`',
                'columns' => "`id`, description, `values`",
                'set' => '',
                'values' => "(NULL, 'abc', 10)",
                'query' => '',
                'on duplicate key update' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfInsertValuesMultiple()
    {
        $query = new Query("INSERT INTO `test` (`id`, description, `values`) VALUES (NULL, 'abc', 10), (NULL, 'bb', 20), (NULL, 'cde', 30)", 'mysql');

        $this->assertEquals(
            [
                'insert' => '',
                'into' => '`test`',
                'columns' => "`id`, description, `values`",
                'set' => '',
                'values' => "(NULL, 'abc', 10), (NULL, 'bb', 20), (NULL, 'cde', 30)",
                'query' => '',
                'on duplicate key update' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfInsertSetSimple()
    {
        $query = new Query("INSERT INTO `test` SET `id`=NULL, description = 'abc'", 'mysql');

        $this->assertEquals(
            [
                'insert' => '',
                'into' => '`test`',
                'columns' => '',
                'set' => "`id`=NULL, description = 'abc'",
                'values' => '',
                'query' => '',
                'on duplicate key update' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfInsertSelectSimple()
    {
        $query = new Query("INSERT INTO `test` SELECT NULL, name FROM xyz", 'mysql');

        $this->assertEquals(
            [
                'insert' => '',
                'into' => '`test`',
                'columns' => '',
                'set' => '',
                'values' => '',
                'query' => "SELECT NULL, name FROM xyz",
                'on duplicate key update' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfInsertSelectSubquery()
    {
        $query = new Query("INSERT INTO `test` SELECT NULL, name FROM xyz WHERE type IN (SELECT type FROM tt GROUP BY type HAVING SUM(qn) > 10)", 'mysql');

        $this->assertEquals(
            [
                'insert' => '',
                'into' => '`test`',
                'columns' => '',
                'set' => '',
                'values' => '',
                'query' => "SELECT NULL, name FROM xyz WHERE type IN (SELECT type FROM tt GROUP BY type HAVING SUM(qn) > 10)",
                'on duplicate key update' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfUpdateSimple()
    {
        $query = new Query("UPDATE `test` SET status='ACTIVE' WHERE id=10", 'mysql');

        $this->assertEquals(
            [
                'update' => '',
                'table' => '`test`',
                'set' => "status='ACTIVE'",
                'where' => 'id=10',
                'limit' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfUpdateAdvanced()
    {
        $query = new Query("UPDATE `test` LEFT JOIN atst ON `test`.id = atst.idTest SET fld1=DEFAULT, afld = CONCAT(a, f, ' (SELECT TRANSPORT)'), status='ACTIVE' WHERE id = 10 LIMIT 20 OFFSET 10", 'mysql');

        $this->assertEquals(
            [
                'update' => '',
                'table' => '`test` LEFT JOIN atst ON `test`.id = atst.idTest',
                'set' => "fld1=DEFAULT, afld = CONCAT(a, f, ' (SELECT TRANSPORT)'), status='ACTIVE'",
                'where' => 'id = 10',
                'limit' => '20 OFFSET 10'
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfUpdateSubquery()
    {
        $query = new Query("UPDATE `test` LEFT JOIN (SELECT idTest, a, f, count(*) AS cnt FROM atst) AS atst ON `test`.id = atst.idTest SET fld1=DEFAULT, afld = CONCAT(a, f, ' (SELECT TRANSPORT)'), status='ACTIVE' WHERE id IN (SELECT id FROM whatever LIMIT 100)", 'mysql');

        $this->assertEquals(
            [
                'update' => '',
                'table' => '`test` LEFT JOIN (SELECT idTest, a, f, count(*) AS cnt FROM atst) AS atst ON `test`.id = atst.idTest',
                'set' => "fld1=DEFAULT, afld = CONCAT(a, f, ' (SELECT TRANSPORT)'), status='ACTIVE'",
                'where' => 'id IN (SELECT id FROM whatever LIMIT 100)',
                'limit' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfDeleteSimple()
    {
        $query = new Query("DELETE FROM `test` WHERE id=10", 'mysql');

        $this->assertEquals(
            [
                'delete' => '',
                'columns' => '',
                'from' => '`test`',
                'where' => 'id=10',
                'order by' => '',
                'limit' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfDeleteAdvanced()
    {
        $query = new Query("DELETE `test`.* FROM `test` INNER JOIN `dude where is my car`.`import` AS dude_import ON `test`.ref = dude_import.ref WHERE dude_import.sql NOT LIKE '% on duplicate key update' AND status = 10 ORDER BY xyz LIMIT 1", 'mysql');

        $this->assertEquals(
            [
                'delete' => '',
                'columns' => '`test`.*',
                'from' => '`test` INNER JOIN `dude where is my car`.`import` AS dude_import ON `test`.ref = dude_import.ref',
                'where' => "dude_import.sql NOT LIKE '% on duplicate key update' AND status = 10",
                'order by' => 'xyz',
                'limit' => '1'
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfDeleteSubquery()
    {
        $query = new Query("DELETE `test`.* FROM `test` INNER JOIN (SELECT * FROM dude_import GROUP BY x_id WHERE status = 'OK' HAVING COUNT(*) > 1) AS dude_import ON `test`.ref = dude_import.ref WHERE status = 10", 'mysql');

        $this->assertEquals(
            [
                'delete' => '',
                'columns' => '`test`.*',
                'from' => "`test` INNER JOIN (SELECT * FROM dude_import GROUP BY x_id WHERE status = 'OK' HAVING COUNT(*) > 1) AS dude_import ON `test`.ref = dude_import.ref",
                'where' => "status = 10",
                'order by' => '',
                'limit' => ''
            ],
            $query->getParts()
        );
    }

    public function testGetPartsOfTruncate()
    {
        $query = new Query("TRUNCATE `test`", 'mysql');
        $this->assertEquals(['truncate' => '', 'table' => '`test`'], $query->getParts());
    }

    public function testGetPartsOfSet()
    {
        $query = new Query("SET abc=10, @def='test'", 'mysql');
        $this->assertEquals(['set' => "abc=10, @def='test'"], $query->getParts());
    }

    public function testGetPartsFail()
    {
        $query = new Query("ALTER TABLE ADD column `foo` varchar(255) NULL");

        $this->expectException(UnsupportedQueryException::class);
        $this->expectExceptionMessage("Unable to split ALTER TABLE query");

        $query->getParts();
    }

    //--------

    public function testGetColumnsSimple()
    {
        $query = new Query("SELECT abc, xyz, test FROM x", 'mysql');
        $this->assertEquals(["abc", "xyz", "test"], $query->getColumns());
    }

    public function testGetColumnsSelect()
    {
        $query = new Query("SELECT abc, CONCAT('abc', 'der', 10+22, IFNULL(`qq`, 'Q')), test, 10+3 AS `bb`, 'Ho, Hi' AS HoHi, 22 FROM test INNER JOIN contact WHERE a='X FROM Y'", 'mysql');

        $this->assertEquals(
            ["abc", "CONCAT('abc', 'der', 10+22, IFNULL(`qq`, 'Q'))", "test", "10+3 AS `bb`", "'Ho, Hi' AS HoHi", "22"],
            $query->getColumns()
        );
    }

    public function testGetColumnsSelectSubquery()
    {
        $query = new Query("SELECT abc, CONCAT('abc', 'der', 10+22, IFNULL(`qq`, 'Q')), x IN (SELECT id FROM xy) AS subq FROM test", 'mysql');

        $this->assertEquals(
            ["abc", "CONCAT('abc', 'der', 10+22, IFNULL(`qq`, 'Q'))", "x IN (SELECT id FROM xy) AS subq"],
            $query->getColumns()
        );
    }

    public function testGetColumnsSelectSubFrom()
    {
        $query = new Query("SELECT abc, CONCAT('abc', 'der', 10+22, IFNULL(`qq`, 'Q')) FROM test INNER JOIN (SELECT id, desc FROM xy) AS subq ON test.id = subq.id", 'mysql');

        $this->assertEquals(
            ["abc", "CONCAT('abc', 'der', 10+22, IFNULL(`qq`, 'Q'))"],
            $query->getColumns()
        );
    }

    public function testGetColumnsSelectRealLifeExample()
    {
        $query = new Query("SELECT relation.id, IF( name = '', CONVERT( concat_name(last_name, suffix, first_name, '')USING latin1 ) , name ) AS fullname FROM relation LEFT JOIN relation_person_type ON relation.id = relation_person_type.relation_id LEFT JOIN person_type ON person_type.id = relation_person_type.person_type_id WHERE person_type_id =5 ORDER BY fullname", 'mysql');

        $this->assertEquals(
            ["relation.id", "IF( name = '', CONVERT( concat_name(last_name, suffix, first_name, '')USING latin1 ) , name ) AS fullname"],
            $query->getColumns()
        );
    }

    public function testGetColumnsInsertValues()
    {
        $query = new Query("INSERT INTO `test` (`id`, description, `values`) VALUES (NULL, 'abc', 10)", 'mysql');
        $this->assertEquals(['`id`', 'description', '`values`'], $query->getColumns());
    }

    public function testGetColumnsInsertSelect()
    {
        $query = new Query("INSERT INTO `test` (`id`, description, `values`) SELECT product_id, title, 22 AS values FROM `abc`", 'mysql');
        $this->assertEquals(['`id`', 'description', '`values`'], $query->getColumns());
    }

    public function testGetColumnsDelete()
    {
        $query = new Query("DELETE test.* FROM `test` INNER JOIN `xyz` ON test.id=xyz.test_id", 'mysql');
        $this->assertEquals(["test.*"], $query->getColumns());
    }

    
    public function testGetSet()
    {
        $query = new Query("SET @abc=18, def=CONCAT('test', '123', DATE_FORMAT(NOW(), '%d-%m-%Y %H:%M')), @uid=NULL");
        
        $this->assertEquals(
            ["@abc" => "18", "def" => "CONCAT('test', '123', DATE_FORMAT(NOW(), '%d-%m-%Y %H:%M'))", "@uid" => "NULL"],
            $query->getSet()
        );
    }

    public function testGetSetInsert()
    {
        $query = new Query("INSERT INTO `test` SET id=1, description='test', `values`=22", 'mysql');

        $this->assertEquals(
            ['id' => '1', "description" => "'test'", 'values' => '22'],
            $query->getSet()
        );
    }

    public function testGetSetUpdate()
    {
        $query = new Query("UPDATE `test` INNER JOIN `xyz` ON test.id=xyz.test_id SET description='test', `values`=22 WHERE test.id=1", 'mysql');
        $this->assertEquals(["description" => "'test'", 'values' => '22'], $query->getSet());
    }

    public function testGetSetUnquote()
    {
        $query = new Query("INSERT INTO `test` SET id=1, description='test', `values`=22", 'mysql');

        $this->assertEquals(
            ['id' => 1, "description" => 'test', 'values' => 22],
            $query->getSet(Query::UNQUOTE)
        );
    }

    public function testGetValues()
    {
        $query = new Query("INSERT INTO `test` VALUES (1, 'test', 22), (2, 'red', DEFAULT)", 'mysql');

        $this->assertEquals(
            [['1', "'test'", '22'], ['2', "'red'", 'DEFAULT']],
            $query->getValues()
        );
    }

    public function testGetValuesUnquote()
    {
        $query = new Query("INSERT INTO `test` VALUES (1, 'test', 22), (2, 'red', DEFAULT)", 'mysql');

        $this->assertEquals(
            [[1, 'test', 22], [2, 'red', null]],
            $query->getValues(Query::UNQUOTE)
        );
    }

    // -------


    public function testGetTablesSimple()
    {
        $query = new Query("SELECT * FROM abc, xyz, mysql.test", 'mysql');
        $this->assertEquals(["abc" => "abc", "xyz" => "xyz", "test" => "mysql.test"], $query->getTables());
    }

    public function testGetTablesAlias()
    {
        $query = new Query("SELECT * FROM abc `a`, `xyz`, mysql.test AS tt", 'mysql');
        $this->assertEquals(["a" => "abc", "xyz" => "`xyz`", "tt" => "mysql.test"], $query->getTables());
    }

    public function testGetTablesJoin()
    {
        $query = new Query("SELECT * FROM abc `a` INNER JOIN ufd.zzz AS `xyz` ON abc.id = xyz.abc_id LEFT JOIN def ON abc.x IN (SELECT abc FROM `xyz_link`) AND abc.y = MYFUNCT(10, 12, xyz.abc_id) STRAIGHT_JOIN tuf, qwerty", 'mysql');

        $this->assertEquals(
            ["a" => "abc", "xyz" => "ufd.zzz", "def" => "def", "tuf" => "tuf", "qwerty" => "qwerty"],
            $query->getTables()
        );
    }

    public function testGetTablesSubjoin()
    {
        $query = new Query("SELECT * FROM abc `a` INNER JOIN (ufd.zzz AS `xyz` LEFT JOIN def ON abc.x IN (SELECT abc FROM `xyz_link`) AND abc.y = def.id, qwerty) ON abc.id = MYFUNCT(10, 12, xyz.abc_id) STRAIGHT_JOIN tuf", 'mysql');

        $this->assertEquals(
            ["a" => "abc", "xyz" => "ufd.zzz", "def" => "def", "qwerty" => "qwerty", "tuf" => "tuf"],
            $query->getTables()
        );
    }

    public function testGetTablesSubquery()
    {
        $query = new Query("SELECT * FROM abc `a` INNER JOIN (SELECT * FROM ufd.zzz AS `xyz` LEFT JOIN def ON abc.y = def.id, qwerty) AS xyz ON abc.id = MYFUNCT(10, 12, xyz.abc_id) STRAIGHT_JOIN tuf", 'mysql');

        $this->assertEquals(
            ["a" => "abc", "xyz" => "(SELECT * FROM ufd.zzz AS `xyz` LEFT JOIN def ON abc.y = def.id, qwerty)", "tuf" => "tuf"],
            $query->getTables()
        );
    }

    public function testGetTablesSelect()
    {
        $query = new Query("SELECT aaa, zzz FROM abc `a` INNER JOIN ufd.zzz AS `xyz` ON abc.id = xyz.abc_id LEFT JOIN def ON abc.x IN (SELECT abc FROM `xyz_link`) AND abc.y = MYFUNCT(10, 12, xyz.abc_id) STRAIGHT_JOIN tuf, qwerty WHERE a='X FROM Y'", 'mysql');

        $this->assertEquals(
            ["a" => "abc", "xyz" => "ufd.zzz", "def" => "def", "tuf" => "tuf", "qwerty" => "qwerty"],
            $query->getTables()
        );
    }

    public function testGetTablesInsertValues()
    {
        $query = new Query("INSERT INTO `test` (`id`, description, `values`) VALUES (NULL, 'abc', 10)", 'mysql');
        $this->assertEquals(["test" => "`test`"], $query->getTables());
    }

    public function testGetTablesInsertSelect()
    {
        $query = new Query("INSERT INTO `test` (`id`, description, `values`) SELECT product_id, title, 22 AS values FROM `abc`", 'mysql');
        $this->assertEquals(["test" => "`test`"], $query->getTables());
    }

    public function testGetTablesInsertSet()
    {
        $query = new Query("INSERT INTO `test` SET id=1, description='test', `values`=22", 'mysql');
        $this->assertEquals(["test" => "`test`"], $query->getTables());
    }

    public function testGetTablesUpdate()
    {
        $query = new Query("UPDATE `test` INNER JOIN `xyz` ON test.id=xyz.test_id SET description='test', `values`=22 WHERE test.id=1", 'mysql');
        $this->assertEquals(["test" => "`test`", "xyz" => "`xyz`"], $query->getTables());
    }

    public function testGetTablesDelete()
    {
        $query = new Query("DELETE test.* FROM `test` INNER JOIN `xyz` ON test.id=xyz.test_id", 'mysql');
        $this->assertEquals(["test" => "`test`", "xyz" => "`xyz`"], $query->getTables());
    }

    //--------


    public function testGetLimit()
    {
        $query = new Query("SELECT * FROM foo LIMIT 10", 'mysql');

        $this->assertEquals(10, $query->getLimit());
        $this->assertNull($query->getOffset());
    }

    public function testGetLimitComma()
    {
        $query = new Query("SELECT * FROM foo LIMIT 50, 10", 'mysql');

        $this->assertEquals(10, $query->getLimit());
        $this->assertEquals(50, $query->getOffset());
    }

    public function testGetLimitOffset()
    {
        $query = new Query("SELECT * FROM foo LIMIT 10 OFFSET 50", 'mysql');

        $this->assertEquals(10, $query->getLimit());
        $this->assertEquals(50, $query->getOffset());
    }

    public function testGetLimitFail()
    {
        $query = new Query("SELECT * FROM foo LIMIT foo, bar", 'mysql');

        $this->expectException(QueryBuildException::class);
        $this->expectExceptionMessage("Invalid limit statement 'foo, bar'");

        $query->getLimit();
    }

    public function testGetLimitNoLimit()
    {
        $query = new Query("SELECT * FROM foo", 'mysql');

        $this->assertNull($query->getLimit());
        $this->assertNull($query->getOffset());
    }

    public function testGetLimitTruncate()
    {
        $query = new Query("TRUNCATE foo", 'mysql');

        $this->expectException(QueryBuildException::class);
        $this->expectExceptionMessage("A TRUNCATE query doesn't have a LIMIT part.");

        $query->getLimit();
    }
}
