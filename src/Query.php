<?php

declare(strict_types=1);

namespace Jasny\Persist\SQL;

use Jasny\Persist\SQL\Query\Builder;
use Jasny\Persist\SQL\Query\Dialect\Generic;
use Jasny\Persist\SQL\Query\QueryBuildException;
use Jasny\Persist\SQL\Query\QuerySplitter;

/**
 * Query builder for MySQL query statements.
 * All editing statements support fluent interfaces.
 */
class Query
{
    /** Prepend to part */
    public const PREPEND = 0x1;
    /** Append to part */
    public const APPEND = 0x2;
    /** Replace part */
    public const REPLACE = 0x4;
    /** Any of the placement options */
    protected const PLACEMENT_OPTIONS = 0x7;

    /** Don't quote identifiers at all */
    public const QUOTE_NONE = 0x100;
    /** Quote identifiers inside expressions */
    public const QUOTE_SMART = 0x200;
    /** Quote each word as identifier */
    public const QUOTE_WORDS = 0x400;
    /** Quote string as field/table name */
    public const QUOTE_STRICT = 0x800;
    /** Any of the backquote options */
    protected const QUOTE_OPTIONS = 0xF00;

    /** Quote value as value when adding a column in a '[UPDATE|INSERT] SET ...' query */
    public const SET_VALUE = 0x1000;
    /** Quote value as expression when adding a column in a '[UPDATE|INSERT] SET ...' query */
    public const SET_EXPRESSION = 0x2000;

    /** Unquote values */
    public const UNQUOTE = 0x4000;
    /** Cast values */
    public const CAST = 0x8000;

    /** Count all rows ignoring limit */
    public const ALL_ROWS = 1;

    /** Sort ascending */
    public const ASC = 0x10;
    /** Sort descending */
    public const DESC = 0x20;


    protected QuerySplitter $splitter;

    /** Base query statement */
    protected string $statement;

    /**
     * Bound parameters
     * @var array<string|int,mixed>
     */
    protected array $params = [];
    
    /** The type of the query. */
    protected string $queryType;

    /**
     * The parts of the split base statement extracted in sets.
     * @var array<string,string>
     */
    protected array $baseParts;

    /**
     * The parts to replace the ones of the base statement.
     * @var array<string,mixed>
     */
    protected array $partsReplace = [];

    /**
     * The parts to add to base statement.
     * @var array<string,mixed>
     */
    protected array $partsAdd = [];

    /**
     * Extracted subqueries
     * @var Query[]
     */
    protected array $subqueries;

    /**
     * The build statement.
     */
    protected string $cachedStatement;

    /**
     * The build parts
     * @var array<string,mixed>
     */
    protected array $cachedParts;

    
    /**
     * Class constructor.
     *
     * @param string                       $statement  SQL statement
     * @param string|Generic|QuerySplitter $dialect
     */
    public function __construct(string $statement, $dialect = 'generic')
    {
        $this->statement = $statement;
        $this->splitter = $dialect instanceof QuerySplitter ? $dialect : new QuerySplitter($dialect);
    }

    /**
     * Get the SQL dialect.
     *
     * @return string
     */
    public function getDialect(): string
    {
        return $this->splitter->getDialect();
    }

    //------------- Splitting -------------

    /**
     * Return the type of the query.
     */
    public function getType(): ?string
    {
        $this->queryType ??= $this->splitter->getQueryType($this->statement);

        return $this->queryType !== '' ? $this->queryType : null;
    }

    /**
     * Return the statement without any added or replaced parts.
     *
     * @return Query
     */
    public function getBaseStatement()
    {
        return new static($this->statement, $this->splitter);
    }

    /**
     * Get the query as an SQL string.
     */
    public function asString(): string
    {
        $this->cachedStatement ??= empty($this->partsAdd) && empty($this->partsReplace)
            ? $this->statement
            : $this->splitter->join($this->getParts());

        return $this->splitter->bind($this->cachedStatement, $this->params);
    }

    /**
     * Cast query object to SQL string.
     */
    public function __toString(): string
    {
        return $this->asString();
    }

    /**
     * Get a subquery (from base statement).
     *
     * @param int $subset Number of subquery (start with 1)
     * @return static
     *
     * @throws \OutOfBoundsException if subquery doesn't exist.
     */
    public function getSubquery(int $subset = 1): self
    {
        if (!isset($this->subqueries)) {
            $this->extractSubqueries();
        }

        if (!isset($this->subqueries[$subset])) {
            $count = count($this->subqueries);
            throw new \OutOfBoundsException(
                "Unable to get subquery #$subset: Query only has $count " . ($count === 1 ? "subquery" : "subqueries")
            );
        }

        return $this->subqueries[$subset];
    }

    /**
     * Extract subqueries from base statement.
     */
    protected function extractSubqueries(): void
    {
        $statements = $this->splitter->extractSubsets($this->statement);

        $this->baseParts = $this->splitter->split($statements[0]);
        $this->subqueries = [];

        unset($statements[0]);

        foreach ($statements as $index => $statement) {
            $this->subqueries[$index] = new static(
                $this->splitter->injectSubsets(...([0 => $statement] + $statements)),
                $this->splitter
            );
        }
    }

    /**
     * Split the base statement
     *
     * @return array<string,string>
     */
    protected function getBaseParts(): array
    {
        $this->baseParts ??= $this->splitter->split($this->statement);

        return $this->baseParts;
    }

    /**
     * Apply the added and replacement parts to the parts of the base query.
     *
     * @return array<string,string>
     */
    public function getParts()
    {
        $this->cachedParts ??= $this->combineParts();

        $parts = empty($this->subqueries)
            ? $this->cachedParts
            : $this->splitter->injectSubsets($this->cachedParts, ...$this->subqueries);

        return array_map('trim', $parts);
    }

    /**
     * Combine base parts with add and replace parts.
     *
     * @return array<string,mixed>
     */
    protected function combineParts(): array
    {
        $parts = $this->getBaseParts();

        // Quick return when nothing is added or replaced
        if ($this->partsAdd === [] && $this->partsReplace === []) {
            return $parts;
        }

        if ($this->partsReplace !== []) {
            $parts = array_merge($parts, $this->partsReplace);
        }
        if ($this->partsAdd !== []) {
            $parts = $this->splitter->addParts($parts, $this->partsAdd);
        }

        if (array_key_first($parts) == 'select' && ($parts['columns'] ?? []) === []) {
            $parts['columns'] = '*';
        }

        if (isset($parts['on duplicate key update']) && trim($parts['on duplicate key update']) === '1') {
            $columns = $this->splitter->splitColumns($parts);
            foreach ($columns as &$column) {
                $column = $this->splitter->quoteIdentifier($column, self::QUOTE_STRICT);
                $column = "$column = VALUES($column)";
            }
            $parts['on duplicate key update'] = join(', ', $columns);
        }

        return $parts;
    }

    /**
     * Return a specific part of the query statement.
     *
     * @param mixed $key  The key identifying the part
     * @return string
     */
    protected function getPart($key)
    {
        return $this->getParts()[$key] ?? null;
    }

    /**
     * Get the tables used in this query statement.
     *
     * @return string[]
     */
    public function getTables(): array
    {
        return $this->splitter->splitTables($this->getParts());
    }

    /**
     * Get the columns used in the query statement.
     *
     * @return array<mixed>
     */
    public function getColumns(): array
    {
        return $this->splitter->splitColumns($this->getParts());
    }

    /**
     * Get the `set` expressions used in the query statement.
     * For `SET ...` and `UPDATE ... SET ...` queries.
     *
     * @param int $flags  Optional Query::UNQUOTE
     * @return array<string,mixed>
     */
    public function getSet(int $flags = 0): array
    {
        return $this->splitter->splitSet($this->getParts(), $flags);
    }

    /**
     * Get the values used in the query statement.
     * For `INSERT INTO ... VALUES ...` and `REPLACE INTO ... VALUES ...` queries.
     *
     * @param int $flags  Optional Query::UNQUOTE
     * @return array<array<mixed>>
     */
    public function getValues(int $flags = 0): array
    {
        return $this->splitter->splitValues($this->getParts(), $flags);
    }

    /**
     * Get the specified LIMIT used in the query.
     */
    public function getLimit(): ?int
    {
        return $this->splitter->splitLimit($this->getParts())[0];
    }

    /**
     * Get the OFFSET specified with the LIMIT used in the query.
     */
    public function getOffset(): ?int
    {
        return $this->splitter->splitLimit($this->getParts())[1];
    }

    //------------- Building -------------

    /**
     * Clear cached statement.
     * This doesn't clear cached columns and values.
     */
    protected function clearCachedStatement(): void
    {
        unset($this->cachedStatement);
        unset($this->cachedParts);
        unset($this->countStatement);
    }

    /**
     * Bind parameters to SQL query.
     *
     *     $query = new Query("SELECT * FROM mytable WHERE id=? AND status=?");
     *     $query->bind($id, $status);
     *
     * @param mixed ...$params
     * @return $this
     */
    public function bind(...$params): self
    {
        $this->params = $params;

        return $this;
    }

    /**
     * Bind named parameters to SQL query.
     *
     *     $query = new Query("SELECT * FROM mytable WHERE name=:name AND age>:age AND status='A'");
     *     $query->bind(['name'=>$name, 'age'=>$age])
     *
     * @param array<mixed> $params
     * @return $this
     */
    public function bindNamed(array $params)
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    /**
     * Add/set an expression to any part of the query.
     *
     * @param mixed           $part       The key identifying the part
     * @param string|string[] $expression
     * @param int             $flags      Query::APPEND, Query::PREPEND or Query::REPLACE
     * @return $this
     */
    protected function setPart($part, $expression, int $flags = Query::APPEND): self
    {
        $part = strtolower($part);

        if (!array_key_exists($part, $this->getBaseParts())) {
            throw new QueryBuildException("A " . $this->getType() . " query doesn't have a $part part");
        }

        $this->clearCachedStatement();

        if ($flags & self::REPLACE) {
            $this->partsReplace[$part] = $expression;
            return $this;
        }

        $placement = $flags & self::PREPEND ? self::PREPEND : self::APPEND;

        if (is_array($expression)) {
            $this->partsAdd[$part][$placement] = isset($this->partsAdd[$part][$placement])
                ? array_merge($this->partsAdd[$part][$placement], $expression)
                : $expression;
        } else {
            $this->partsAdd[$part][$placement][] = $expression;
        }

        return $this;
    }

    /**
     * Add a table.
     *
     * @param string $table
     * @param string $joinType  LEFT JOIN, INNER JOIN, etc
     * @param string $joinOn    that.field = this.field
     * @param int    $flags     Query::REPLACE, Query::PREPEND/Query::APPEND, Query::QUOTE_%
     * @return $this
     */
    protected function addTable(string $table, string $joinType = '', string $joinOn = '', int $flags = 0): self
    {
        $typeMap = ['INSERT' => 'into', 'REPLACE' => 'into', 'UPDATE' => 'table', 'TRUNCATE' => 'table'];
        $part = $typeMap[$this->getType()] ?? 'from';

        if (($flags & self::QUOTE_OPTIONS) === 0) {
            $flags |= self::QUOTE_WORDS;
        }
        if (($flags & self::PLACEMENT_OPTIONS) === 0) {
            $flags |= $joinType ? self::APPEND : self::REPLACE;
        }

        if ($joinType === '' && ($flags & self::REPLACE) === 0) {
            $joinType = ',';
        }

        $table = $this->splitter->quoteIdentifier($table, $flags);
        $joinOn = $joinType !== '' && $joinOn !== ''
            ? $this->splitter->quoteIdentifier($joinOn, $flags & ~self::QUOTE_OPTIONS | self::QUOTE_SMART)
            : '';

        if ($flags & self::REPLACE) {
            if ($joinType !== '') {
                throw new QueryBuildException("$joinType specified when replacing the table");
            }

            $this->setPart($part, $table, $flags);
        } elseif ($flags & self::PREPEND) {
            $this->setPart($part, $table . ($joinType !== '' ? ' ' . $joinType : ''), $flags);
            if ($joinOn !== '') {
                $this->setPart($part, "ON $joinOn", $flags & ~self::PREPEND | self::APPEND);
            }
        } else {
            $this->setPart($part, $joinType . ' ' . $table . ($joinOn !== '' ? " ON $joinOn" : ""), $flags);
        }

        return $this;
    }

    /**
     * Set the FROM table of a SELECT query.
     *
     * @param string $table
     * @param int    $flags  Query::QUOTE_%
     * @return $this
     */
    public function from(string $table, int $flags = 0): self
    {
        return $this->addTable($table, '', '', $flags);
    }

    /**
     * Set the table of an UPDATE query.
     *
     * @param string $table
     * @param int    $flags  Query::REPLACE, Query::PREPEND/Query::APPEND, Query::QUOTE_%
     * @return $this
     */
    public function table(string $table, int $flags = 0): self
    {
        return $this->addTable($table, '', '', $flags);
    }

    /**
     * Set the INTO table of an INSERT query.
     *
     * @param string $table
     * @param int    $flags  Query::REPLACE, Query::PREPEND/Query::APPEND, Query::QUOTE_%
     * @return $this
     */
    public function into(string $table, int $flags = 0): self
    {
        return $this->addTable($table, '', '', $flags);
    }

    /**
     * Add an inner join to the query.
     *
     * @param string $table
     * @param string $on
     * @param int    $flags  Query::QUOTE_%
     * @return $this
     */
    public function innerJoin(string $table, string $on = '', int $flags = 0): self
    {
        return $this->addTable($table, "INNER JOIN", $on, $flags);
    }

    /**
     * Add a left join to the query.
     *
     * @param string $table
     * @param string $on
     * @param int    $flags  Query::QUOTE_%
     * @return $this
     */
    public function leftJoin(string $table, string $on = '', int $flags = 0): self
    {
        return $this->addTable($table, "LEFT JOIN", $on, $flags);
    }

    /**
     * Add a right join to the query.
     *
     * @param string $table
     * @param string $on
     * @param int    $flags  Query::QUOTE_%
     * @return $this
     */
    public function rightJoin(string $table, string $on = '', int $flags = 0): self
    {
        return $this->addTable($table, "RIGHT JOIN", $on, $flags);
    }

    /**
     * Add a custom join to the query.
     *
     * @param string $join
     * @param string $table
     * @param string $on
     * @param int    $flags  Query::QUOTE_%
     * @return $this
     */
    public function join(string $join, string $table, string $on = '', int $flags = 0): self
    {
        return $this->addTable($table, $join, $on, $flags);
    }

    /**
     * Add column(s) to query statement.
     *
     * Flags:
     *  Position:   Query::REPLACE, Query::PREPEND or Query::APPEND (default)
     *  Quote expr: Query::QUOTE_%
     *
     * @param mixed $column  Column name or array(column, ...)
     * @param int   $flags   Options as bitset
     * @return $this
     */
    public function columns($column, $flags = 0)
    {
        if (is_array($column)) {
            foreach ($column as $key => &$col) {
                $col = $this->splitter->quoteIdentifier($col, $flags)
                    . (!is_int($key) ? ' AS ' . $this->splitter->quoteIdentifier($key, Query::QUOTE_STRICT) : '');
            }

            $column = join(', ', $column);
        } else {
            $column = $this->splitter->quoteIdentifier($column, $flags);
        }

        return $this->setPart('columns', $column, $flags);
    }

    /**
     * Alias of Query::columns().
     *
     * @param mixed $column  Column name or array(column, ...)
     * @param int   $flags   Options as bitset
     * @return $this
     */
    final public function column($column, $flags = 0)
    {
        return $this->columns($column, $flags);
    }

    /**
     * Add an expression to the SET part of an INSERT SET ... or UPDATE SET query
     *
     * Flags:
     *  Position:   Query::REPLACE, Query::PREPEND or Query::APPEND (default)
     *  Set:        Query::SET_EXPRESSION or Query::SET_VALUE (default)
     *  Quote expr: Query::QUOTE_%
     *
     * For an INSERT INTO ... SELECT query $column should be a Query object
     *
     * @param string|array $column  Column name or array(column => value, ...)
     * @param mixed        $value   Value or expression (omit if $column is an array)
     * @param int          $flags   Options as bitset
     * @return $this
     */
    public function set($column, $value = null, $flags = 0)
    {
        $type = $this->getType();

        // $value is omitted and $flags is specified as second argument
        if (is_array($column) && func_num_args() === 2 && is_int($value)) {
            $flags = $value;
        }

        // INSERT INTO ... SELECT ..
        if (($type == 'INSERT' || $type == 'REPLACE') && (
            ($column instanceof self && $column->getType() == 'SELECT') ||
            (is_string($column) && $value === null && $this->splitter->getQueryType($column) === 'SELECT')
        )) {
            return $this->setPart('query', $column, $flags);
        }

        // INSERT INTO ... SET ...
        $columns = is_array($column) ? $column : [$column => $value];
        $empty = ($this->getType() == 'INSERT' || $this->getType() == 'REPLACE') ? 'DEFAULT' : 'NULL';

        if (($flags & self::SET_EXPRESSION) !== 0) {
            foreach ($columns as $key => &$val) {
                $isKeyValue = strpos($key, '=') !== false;
                $keyFlags = $isKeyValue ? $flags : $flags & ~self::QUOTE_OPTIONS | self::QUOTE_STRICT;
                $val = $this->splitter->quoteIdentifier($key, $keyFlags)
                    . ($isKeyValue ? '' : ' = ' . $this->splitter->quoteIdentifier($val, $flags));
            }
        } else {
            foreach ($columns as $key => &$val) {
                $isKeyValue = strpos($key, '=') !== false;
                $keyFlags = $isKeyValue ? $flags : $flags & ~self::QUOTE_OPTIONS | self::QUOTE_STRICT;
                $val = $this->splitter->quoteIdentifier($key, $keyFlags)
                    . ($isKeyValue ? '' : ' = ' . $this->splitter->quoteValue($val, $empty));
            }
        }

        return $this->setPart('set', join(', ', $columns), $flags);
    }



    /**
     * Add a row of values to an "INSERT ... VALUES (...)" query statement.
     *
     * @param string|array<mixed>|array<array<mixed>> $values  Statement (string), array of values or array with rows
     * @param int   $flags                                     Query::REPLACE/PREPEND/APPEND
     * @return $this
     */
    public function values($values, int $flags = 0)
    {
        if (is_array($values)) {
            $values = is_array(reset($values)) ? $values : [$values];

            foreach ($values as &$row) {
                foreach ($row as &$value) {
                    $value = $this->splitter->quoteValue($value, 'DEFAULT');
                }
                $row = join(', ', $row);
            }
        }

        return $this->setPart('values', $values, $flags);
    }

    /**
     * Add criteria as WHERE expression to query statement.
     *
     *     $query->where('foo', 10);                                 // WHERE `foo` = 10
     *     $query->where('foo > ?', 10);                             // WHERE `foo` > 10
     *     $query->where('foo IS NULL');                             // WHERE `foo` IS NULL
     *     $query->where('foo', [10, 20]);                           // WHERE `foo` IN (10, 20)
     *     $query->where('foo BETWEEN ? AND ?', [10, 20[);           // WHERE `foo` BETWEEN 10 AND 20
     *     $query->where('bar LIKE %?%', "blue");                    // WHERE `bar` LIKE "%blue%"
     *     $query->where('foo = ? AND bar LIKE %?%', [10, "blue"]);  // WHERE `foo` = 10 AND `bar` LIKE "%blue%"
     *     $query->where(['foo'=>10, 'bar'=>"blue"]);                // WHERE `foo` = 10 AND `bar` = "blue"
     *
     * @param mixed $column  Expression, column name, expression with placeholders or [column=>value, ...]
     * @param mixed $value   Value or array of values
     * @param int   $flags   Query::REPLACE/PREPEND/APPEND, Query::QUOTE_%
     * @return $this
     */
    public function where($column, $value = null, int $flags = 0)
    {
        $where = $this->splitter->buildWhere($column, $value, $flags);

        if ($where !== null) {
            $this->setPart('where', $where, $flags);
        }

        return $this;
    }

    /**
     * Add criteria as HAVING expression to query statement.
     * @see Query::where()
     *
     * @param mixed $column  Expression, column name, expression with placeholders or [column=>value, ...]
     * @param mixed $value   Value or array of values
     * @param int   $flags   Query::REPLACE/PREPEND/APPEND, Query::QUOTE_%
     * @return $this
     */
    public function having($column, $value = null, int $flags = 0): self
    {
        $where = $this->splitter->buildWhere($column, $value, $flags);

        if ($where !== null) {
            $this->setPart('having', $where, $flags);
        }

        return $this;
    }

    /**
     * Add GROUP BY expression to query statement.
     *
     * @param string|array $column  GROUP BY expression (string) or array with columns
     * @param int          $flags   Query::REPLACE/PREPEND/APPEND, Query::QUOTE_%
     * @return $this
     */
    public function groupBy($column, int $flags = 0)
    {
        if (is_scalar($column)) {
            $column = $this->splitter->quoteIdentifier($column, $flags);
        } else {
            foreach ($column as &$col) {
                $col = $this->splitter->quoteIdentifier($col, $flags);
            }
            $column = join(', ', $column);
        }

        return $this->setPart('group by', $column, $flags);
    }

    /**
     * Add ORDER BY expression to query statement.
     *
     * @param mixed $column  ORDER BY expression (string) or array with columns
     * @param int   $flags   Query/DESC, Query::REPLACE/PREPEND/APPEND, Query::QUOTE_%
     * @return $this
     */
    public function orderBy($column, int $flags = 0): self
    {
        $columns = is_array($column) ? $column : [$column];

        if (($flags & (self::APPEND | self::PREPEND)) === 0) {
            $flags |= self::PREPEND;
        }

        foreach ($columns as &$col) {
            $col = $this->splitter->quoteIdentifier($col, $flags)
                . ($flags & self::DESC ? ' DESC' : '')
                . ($flags & self::ASC ? ' ASC' : '');
        }

        $part = join(', ', $columns);

        return $this->setPart('order by', $part, $flags);
    }

    /**
     * Add ON CONFLICT DO UPDATE / ON DUPLICATE KEY UPDATE to an INSERT query.
     *
     * @todo This is MySQL specific. Fix for other dialects.
     *
     * @param mixed $column      Column name, [column, ...] or ['column' => expression, ...]
     * @param mixed $expression  Expression or value
     * @param int   $flags       Query::SET_VALUE, Query::SET_EXPRESSION, Query::REPLACE/PREPEND/APPEND, Query::QUOTE_%
     * @return $this
     */
    public function onConflictUpdate($column = true, $expression = null, int $flags = 0): self
    {
        if ($column === true) {
            return $this->setPart('on duplicate key update', '1', $flags);
        }

        $columns = is_array($column)
            ? $column
            : ($expression === null ? [$column] : [$column => $expression]);

        foreach ($columns as $key => &$val) {
            if (is_int($key)) {
                $field = $val;
                $expr = "VALUES("
                    . $this->splitter->quoteIdentifier($val, $flags & ~self::QUOTE_OPTIONS | self::QUOTE_STRICT)
                    . ")";
            } else {
                $field = $key;
                $expr = ($flags & self::SET_VALUE) || !is_string($val)
                    ? $this->splitter->quoteValue($val, 'DEFAULT')
                    : $this->splitter->quoteIdentifier($val, $flags);
            }

            $val = $this->splitter->quoteIdentifier($field, $flags & ~self::QUOTE_OPTIONS | self::QUOTE_STRICT)
                . ' = ' . $expr;
        }

        return $this->setPart('on duplicate key update', join(', ', $columns), $flags);
    }

    /**
     * Alias of onConflictUpdate().
     *
     * @param mixed $column      Column name, array(column, ...) or array('column' => expression, ...)
     * @param mixed $expression  Expression or value
     * @param int   $flags       Query::SET_VALUE, Query::SET_EXPRESSION, Query::REPLACE/PREPEND/APPEND, Query::QUOTE_%
     * @return $this
     */
    final public function onDuplicateKeyUpdate($column = true, $expression = null, int $flags = 0): self
    {
        return $this->onConflictUpdate($column, $expression, $flags);
    }

    /**
     * Set the limit for the number of rows returned when executed.
     *
     * @param int|string $rowcount  Number of rows of full limit statement
     * @param int        $offset    Start at row
     * @return $this
     */
    public function limit($rowcount, int $offset = 0): self
    {
        return $this->setPart('limit', $rowcount . ($offset !== 0 ? " OFFSET $offset" : ""), self::REPLACE);
    }

    /**
     * Set the limit by specifying the page.
     *
     * @param int      $page      Page numer, starts with page 1
     * @param int|null $rowcount  Number of rows per page
     * @return $this
     */
    public function page(int $page, ?int $rowcount = null): self
    {
        if ($rowcount === null) {
            $limit = $this->getPart('limit');
            if (strpos($limit, ',') !== false) {
                $limit = substr($limit, strpos($limit, ',') + 1);
            }

            $rowcount = $limit !== null ? (int)trim($limit) : 0;
            if ($rowcount === 0) {
                throw new QueryBuildException("Unable to set limit offset: rowcount couldn't be determined");
            }
        }

        return $this->limit($rowcount, $rowcount * ($page - 1));
    }

    /**
     * Set the options part of a query.
     *
     * @param string $options
     * @param int    $flags
     * @return $this
     */
    public function options(string $options, int $flags = 0)
    {
        return $this->setPart('options', $options, $flags);
    }
    

    /**
     * Get a query to count the number of rows that the result would contain.
     *
     * @param int $flags  Query::ALL_ROWS
     * @return static
     */
    public function count(int $flags = 0): self
    {
        return new static(
            $this->splitter->buildCountQuery($this->getParts(), $flags),
            $this->splitter
        );
    }


    /**
     * Quote a value so it can be safely used in a query.
     *
     * @param mixed  $value
     * @param string $empty  Return $empty if $value is null
     * @return string
     */
    public function quoteValue($value, string $empty = 'NULL'): string
    {
        return $this->splitter->quoteValue($value, $empty);
    }

    /**
     * Quotes a string so it can be used as a table or field name.
     * Dots are seen as separator and are kept out of quotes.
     *
     * Quoting expressions without Query::QUOTE_STRICT is not safe.
     *
     * @param string $identifier
     * @param int    $flags       Query::QUOTE_%
     * @return string
     * @throws QueryBuildException
     */
    public function quoteIdentifier(string $identifier, int $flags = self::QUOTE_STRICT): string
    {
        return $this->splitter->quoteIdentifier($identifier, $flags);
    }


    /**
     * Get the factory to build a new query.
     *
     * @param string $dialect  SQL dialect
     * @return Builder
     */
    public static function build(string $dialect = 'generic'): Builder
    {
        return new Builder($dialect);
    }
}
