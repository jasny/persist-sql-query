Jasny's Query builder for PHP
=============================

[![Build Status](https://secure.travis-ci.org/jasny/dbquery.png?branch=master)](http://travis-ci.org/jasny/dbquery)

This library is designed to be the ultimate tool for building, splitting and modifying SQL queries.

Automatic smart quoting helps against SQL injection and problems with reserved keywords.

DBQuery can be used standalone, in conjunction with [Jasny's DB layer](http://jasny.github.com/db) or in almost any
framework.

_The current version only support MySQL SQL queries_

## Installation ##

Jasny's Query builder is registred at packagist as [jasny/dbquery-mysql](https://packagist.org/packages/jasny/dbquery-mysql)
and can be easily installed using [composer](http://getcomposer.org/). Alternatively you can simply download the .zip
and copy the file from the 'src' folder.

## Examples ##

An example to simple to be using a query builder

    <?php
        use Jasny\DB\MySQL\Query;
        
        $query = Query::select()->columns('id, name')->from('foo')->where('active = 1');
        $result = $mysqli->query($query); // SELECT `id`, `name` FROM `foo` WHERE `active` = 1

Dynamicly apply paging and filtering on a query

    <?php
        use Jasny\DB\MySQL\Query;
        
        $query = new Query("SELECT * FROM foo LEFT JOIN bar ON foo.bar_id = bar.id WHERE active = 1 LIMIT 25");
        if (isset($_GET['page'])) $query->page(3);

        $filter = isset($_POST['filter']) ? $_POST['filter'] : array(); // array('type' => 'bike', 'price between ? and ?' => array(10, 20))
        foreach ($filter as $field => $value) {
            $query->where($field, $value);
        }

        $result = $mysqli->query($query); // SELECT * FROM foo LEFT JOIN bar ON foo.bar_id = bar.id WHERE (active = 1) AND (`type` = "bike") AND (`price` between 10 and 20) LIMIT 25 OFFSET 50

Map fields for an INSERT INTO ... SELECT ... ON DUPLICATE KEY query

    <?php
        use Jasny\DB\MySQL\Query;
        
        $columns = array(
            'ref' => 'ref',
            'man' => 'boy',
            'woman' => 'girl',
            'amount' => 'SUM(z.bucks)'
        );

        $select = Query::select()->columns($columns)->from('foo')->innerJoin('z', 'foo.id = z.foo_id')->groupBy('foo.id');
        $insert = Query::insert()->into('abc')->columns(array_keys($columns))->set($select)->onDuplicateKeyUpdate();

        $mysql->query($insert); // INSERT INTO `abc` (`ref`, `man`, `woman`, `amount`)
                                //  SELECT `ref` AS `ref`, `boy` AS `man`, `girl` AS `woman`, SUM(`z`.`bucks`) AS `amount` FROM `foo` LEFT JOIN `z` ON `foo`.`id` = `z`.`foo_id` GROUP BY `foo`.id`
                                //  ON DUPLICATE KEY UPDATE `ref` = VALUES(`ref`), `man` = VALUES(`man`), `woman` = VALUES(`woman`), `amount` = VALUES(`amount`)

## API documentation (generated) ##

http://jasny.github.com/dbquery/docs
