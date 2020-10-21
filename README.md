![jasny-banner](https://user-images.githubusercontent.com/100821/62123924-4c501c80-b2c9-11e9-9677-2ebc21d9b713.png)

Persist - SQL Query
===================================

This library is designed to be the ultimate tool for building, splitting and modifying queries.

Automatic smart quoting helps against SQL injection and problems with reserved keywords.

Supported SQL dialects

* Generic (ANSI standard)
* MySQL
* PostgreSQL _(todo)_
* SQLite _(todo)_

## Installation ##

    composer install jasny/persist-sql-query

## Examples ##

Add `WHERE` conditions and an `OFFSET` to a `SELECT` query

```php
use Jasny\Persist\SQL\Query;

$query = (new Query("SELECT * FROM foo LEFT JOIN bar ON foo.bar_id = bar.id WHERE active = 1 LIMIT 25"))
    ->where('type', 'bike')
    ->where('price between ? and ?', [10, 20])
    ->page(3);

echo $query; // SELECT * FROM foo LEFT JOIN bar ON foo.bar_id = bar.id WHERE (active = 1) AND (`type` = 'bike') AND (`price` between 10 and 20) LIMIT 25 OFFSET 50
```

Map fields for an `INSERT INTO ... SELECT ... ON DUPLICATE KEY` query

```php
use Jasny\Persist\SQL\Query;

$columns = [
    'ref' => 'ref',
    'man' => 'boy',
    'woman' => 'girl',
    'amount' => 'SUM(z.bucks)'
];

$build = Query::build('mysql');

$select = $build->select()->columns($columns)->from('foo')->innerJoin('z', 'foo.id = z.foo_id')->groupBy('foo.id');
$insert = $build->insert()->into('abc')->columns(array_keys($columns))->set($select)->onDuplicateKeyUpdate();

echo $insert; // INSERT INTO `abc` (`ref`, `man`, `woman`, `amount`)
              //   SELECT `ref` AS `ref`, `boy` AS `man`, `girl` AS `woman`, SUM(`z`.`bucks`) AS `amount` FROM `foo` LEFT JOIN `z` ON `foo`.`id` = `z`.`foo_id` GROUP BY `foo`.id`
              //   ON DUPLICATE KEY UPDATE `ref` = VALUES(`ref`), `man` = VALUES(`man`), `woman` = VALUES(`woman`), `amount` = VALUES(`amount`)
```
