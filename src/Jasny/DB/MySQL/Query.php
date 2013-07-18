<?php

namespace Jasny\DB\MySQL;

require_once __DIR__ . '/QuerySplitter.php';

/**
 * Query builder for MySQL query statements.
 * All editing statements support fluent interfaces.
 */
class Query
{
    /** Prepend to part */

    const PREPEND = 0x1;
    /** Append to part */
    const APPEND = 0x2;
    /** Replace part */
    const REPLACE = 0x4;
    /**
     * Any of the placement options
     * @ignore
     */
    const _PLACEMENT_OPTIONS = 0x7;

    /** Don't quote identifiers at all */
    const BACKQUOTE_NONE = 0x100;
    /** Quote identifiers inside expressions */
    const BACKQUOTE_SMART = 0x200;
    /** Quote each word as identifier */
    const BACKQUOTE_WORDS = 0x400;
    /** Quote string as field/table name */
    const BACKQUOTE_STRICT = 0x800;
    /**
     * Any of the backquote options
     * @ignore
     */
    const _BACKQUOTE_OPTIONS = 0xF00;

    /** Quote value as value when adding a column in a '[UPDATE|INSERT] SET ...' query */
    const SET_VALUE = 0x1000;
    /** Quote value as expression when adding a column in a '[UPDATE|INSERT] SET ...' query */
    const SET_EXPRESSION = 0x2000;

    /** Unquote values */
    const UNQUOTE = 0x4000;
    /** Cast values */
    const CAST = 0x8000;

    /** Count all rows ignoring limit */
    const ALL_ROWS = 1;

    /** Sort ascending */
    const ASC = 0x10;
    /** Sort descending */
    const DESC = 0x20;

    /**
     * Query statement
     * @var string
     */
    protected $statement;

    /**
     * The type of the query
     * @var string
     */
    protected $queryType;

    /**
     * The parts of the split base statement extracted in sets
     * @var array
     */
    protected $baseParts;

    /**
     * The parts to replace the ones of the base statement.
     * @var array
     */
    protected $partsReplace;

    /**
     * The parts to add to base statement.
     * @var array
     */
    protected $partsAdd;

    /**
     * Extracted subqueries
     * @var Query[]
     */
    protected $subqueries;

    /**
     * The build statements
     * @var string
     */
    protected $cachedStatement;

    /**
     * The build parts
     * @var array
     */
    protected $cachedParts;

    /**
     * Extracted table names
     * @var array
     */
    protected $cachedTablenames;

    /**
     * Class constructor.
     * Don't mix both types ('?' and ':key') of placeholders.
     * 
     * @example new Query("SELECT * FROM mytable");
     * @example new Query("SELECT * FROM mytable WHERE id=?", $id);
     * @example new Query("SELECT * FROM mytable WHERE name=:name AND age>:age AND status='A'", array('id'=>$id, 'age'=>$age));
     * 
     * @param string $statement  Query statement
     * @param mixed  $params     Parameters for placeholders
     */
    public function __construct($statement, $params = array())
    {
        if (func_num_args() > 1) {
            if (!is_array($params) || is_int(key($params))) {
                $params = func_get_args();
                $params = array_splice($params, 1);
            }

            if (!empty($params)) $statement = QuerySplitter::bind($statement, $params);
        }

        $this->statement = $statement;
    }

    //------------- Splitting -------------

    /**
     * Return the type of the query
     *
     * @return string
     */
    public function getType()
    {
        if (!isset($this->queryType)) $this->queryType = QuerySplitter::getQueryType($this->statement);
        return $this->queryType;
    }

    /**
     * Return the statement without any added or replaced parts.
     *
     * @return Query  $this
     */
    public function getBaseStatement()
    {
        return new static($this->statement, $this);
    }

    /**
     * Cast query object to SQL string.
     *
     * @return string
     */
    public function __toString()
    {
        if (empty($this->partsAdd) && empty($this->partsReplace)) return $this->statement;

        if (!isset($this->cachedStatement)) $this->cachedStatement = QuerySplitter::join($this->getParts());
        return $this->cachedStatement;
    }

    /**
     * Get a subquery (from base statement).
     * 
     * @param int $subset  Number of subquery (start with 1)
     * @return Query
     */
    public function getSubquery($subset = 1)
    {
        if (!isset($this->subqueries)) {
            $statements = QuerySplitter::extractSubsets($this->statement);
            $this->baseParts = QuerySplitter::split($statements[0]);
            unset($statements[0]);

            foreach ($statements as $i => $statement) $this->subqueries[$i] = new static($statement, $this);
        }

        if (!isset($this->subqueries[$subset])) throw new \Exception("Unable to get subquery #$subset: Query only has " . count($this->subqueries) . (count($this->subqueries) == 1 ? " subquery." : " subqueries."));
        return $this->subqueries[$subset];
    }

    /**
     * Split the base statement
     * 
     * @return array
     */
    protected function getBaseParts()
    {
        if (!isset($this->baseParts)) $this->baseParts = QuerySplitter::split($this->statement);
        return $this->baseParts;
    }

    /**
     * Apply the added and replacement parts to the parts of the base query.
     * 
     * @return array
     */
    public function getParts()
    {
        if (!isset($this->cachedParts)) {
            $parts = $this->getBaseParts();
            if (empty($this->partsAdd) && empty($this->partsReplace)) return $parts;

            if (!empty($this->partsReplace)) $parts = array_merge($parts, $this->partsReplace);
            if (!empty($this->partsAdd)) $parts = QuerySplitter::addParts($parts, $this->partsAdd);
            if (key($parts) == 'select' && empty($parts['columns'])) $parts['columns'] = '*';

            if (isset($parts['on duplicate key update']) && trim($parts['on duplicate key update']) === '1') {
                $columns = QuerySplitter::splitColumns($parts);
                foreach ($columns as &$column) {
                    $column = "$column = VALUES($column)";
                }
                $parts['on duplicate key update'] = join(', ', $columns);
            }

            $this->cachedParts = & $parts;
        }

        return empty($this->subqueries) ? $this->cachedParts : QuerySplitter::injectSubsets(array($this->cachedParts) + $this->subqueries);
    }

    /**
     * Return a specific part of the statement.
     *
     * @param mixed $key  The key identifying the part
     * @return string
     */
    protected function getPart($key)
    {
        $parts = $this->getParts();
        return isset($parts[$key]) ? $parts[$key] : null;
    }

    /**
     * Get the tables used in this statement.
     *
     * @param int $flags  Query::SPLIT_% options
     * @return DB_Table
     */
    public function getTables($flags = 0)
    {
        return QuerySplitter::splitTables($this->getParts(), $flags);
    }

    /**
     * Get the columns used in the statement.
     * 
     * @param int $flags  Query::SPLIT_% and Query::UNQUOTE options
     * @return array
     */
    public function getColumns($flags = 0)
    {
        return QuerySplitter::splitColumns($this->getParts(), $flags);
    }

    /**
     * Get the values used in the statement.
     * Only for INSERT INTO ... VALUES ... query.
     * 
     * @param int $flags  Optional Query::UNQUOTE
     * @return array
     */
    public function getValues($flags = 0)
    {
        return QuerySplitter::splitValues($this->getParts(), $flags);
    }

    //------------- Building -------------

    /**
     * Clear cached statement.
     * This doesn't clear cached columns and values.
     */
    protected function clearCachedStatement()
    {
        $this->cachedStatement = null;
        $this->cachedParts = null;
        $this->countStatement = null;
    }

    /**
     * Add/set an expression to any part of the query.
     * 
     * @param mixed  $part       The key identifying the part
     * @param string $expression
     * @param int    $flags      Query::APPEND, Query::PREPEND or Query::REPLACE
     */
    protected function setPart($part, $expression, $flags = Query::APPEND)
    {
        $part = strtolower($part);

        if (!array_key_exists($part, $this->getBaseParts())) throw new \Exception("A " . $this->getType() . " query doesn't have a $part part");

        $this->clearCachedStatement();

        if ($flags & self::REPLACE) {
            $this->partsReplace[$part] = $expression;
        } else {
            $this->partsAdd[$part][$flags & self::PREPEND ? self::PREPEND : self::APPEND][] = $expression;
        }
    }

    /**
     * Add a table.
     * 
     * @param string $table
     * @param string $joinType  LEFT JOIN, INNER JOIN, etc
     * @param string $joinOn    that.field = this.field
     * @param int    $flags     Query::REPLACE, Query::PREPEND or Query::APPEND + Query::BACKQUOTE_% options as bitset.
     * @return Query  $this
     */
    protected function addTable($table, $joinType = null, $joinOn = null, $flags = 0)
    {
        switch ($this->getType()) {
            case 'INSERT':
            case 'REPLACE': $part = 'into';
                break;
            case 'UPDATE': $part = 'table';
                break;
            default: $part = 'from';
        }

        if (!($flags & self::_BACKQUOTE_OPTIONS)) $flags |= self::BACKQUOTE_WORDS;
        if (!($flags & self::_PLACEMENT_OPTIONS)) $flags |= $joinType ? self::APPEND : self::REPLACE;
        if (!isset($joinType) && ~$flags & self::REPLACE) $joinType = ',';

        $table = QuerySplitter::backquote($table, $flags);
        $joinOn = QuerySplitter::backquote($joinOn, $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_SMART);

        if ($flags & self::REPLACE) {
            $this->setPart($part, $table, $flags);
        } elseif ($flags & self::PREPEND) {
            $this->setPart($part, $table . ($joinType ? ' ' . $joinType : ''), $flags);
            if (!empty($joinOn)) $this->setPart($part, "ON $joinOn", self::APPEND);
        } else {
            $this->setPart($part, $joinType . ' ' . $table . (!empty($joinOn) ? " ON $joinOn" : ""), $flags);
        }

        return $this;
    }

    /**
     * Set the FROM table of a SELECT query.
     * 
     * @param string $table  tablename
     * @param int    $flags  Query::BACKQUOTE_% options as bitset.
     * @return Query  $this
     */
    public function from($table, $flags = 0)
    {
        return $this->addTable($table, null, null, $flags);
    }

    /**
     * Set the table of an UPDATE query.
     * 
     * @param string $table    tablename
     * @param int    $flags    Query::REPLACE, Query::PREPEND or Query::APPEND + Query::BACKQUOTE_% options as bitset.
     * @return Query  $this
     */
    public function table($table, $flags = 0)
    {
        return $this->addTable($table, null, null, $flags);
    }

    /**
     * Set the INTO table of an INSERT query.
     * 
     * @param string $table    tablename
     * @param int    $flags    Query::REPLACE, Query::PREPEND or Query::APPEND + Query::BACKQUOTE_% options as bitset.
     * @return Query  $this
     */
    public function into($table, $flags = 0)
    {
        return $this->addTable($table, null, null, $flags);
    }

    /**
     * Add an inner join to the query.
     * 
     * @param string $table  tablename
     * @param int    $flags  Query::BACKQUOTE_% options as bitset.
     * @return Query  $this
     */
    public function innerJoin($table, $on = null, $flags = 0)
    {
        return $this->addTable($table, "INNER JOIN", $on, $flags);
    }

    /**
     * Add an inner join to the query.
     * 
     * @param string $table  tablename
     * @param int    $flags  Query::BACKQUOTE_% options as bitset.
     * @return Query  $this
     */
    public function leftJoin($table, $on, $flags = 0)
    {
        return $this->addTable($table, "LEFT JOIN", $on, $flags);
    }

    /**
     * Add an inner join to the query.
     * 
     * @param string $table  tablename
     * @param int    $flags  Query::BACKQUOTE_% options as bitset.
     * @return Query  $this
     */
    public function rightJoin($table, $on, $flags = 0)
    {
        return $this->addTable($table, "RIGHT JOIN", $on, $flags);
    }

    /**
     * Add column(s) to query statement.
     * 
     * Flags:
     *  Position:   Query::REPLACE, Query::PREPEND or Query::APPEND (default)
     *  Quote expr: Query::BACKQUOTE_%
     *
     * @param mixed $column  Column name or array(column, ...)
     * @param int   $flags   Options as bitset
     * @return Query  $this
     */
    public function columns($column, $flags = 0)
    {
        if (is_array($column)) {
            foreach ($column as $key => &$col) {
                $col = QuerySplitter::backquote($col, $flags) . (!is_int($key) ? ' AS ' . QuerySplitter::backquote($key, Query::BACKQUOTE_STRICT) : '');
            }

            $column = join(', ', $column);
        } else {
            $column = QuerySplitter::backquote($column, $flags);
        }

        $this->setPart('columns', $column, $flags);

        return $this;
    }

    /**
     * Alias of Query::columns().
     *
     * @param mixed $column  Column name or array(column, ...)
     * @param int   $flags   Options as bitset
     * @return Query  $this
     */
    public function column($column, $flags = 0)
    {
        return $this->columns($column, $flags);
    }

    /**
     * Add an expression to the SET part of an INSERT SET ... or UPDATE SET query
     * 
     * Flags:
     *  Position:   Query::REPLACE, Query::PREPEND or Query::APPEND (default)
     *  Set:        Query::SET_EXPRESSION or Query::SET_VALUE (default)
     *  Quote expr: Query::BACKQUOTE_%
     *
     * For an INSERT INTO ... SELECT query $column should be a Query object
     * 
     * @param string|array $column  Column name or array(column => value, ...)
     * @param mixed        $value   Value or expression (omit if $column is an array)
     * @param int          $flags   Options as bitset
     * @return Query  $this
     */
    public function set($column, $value = null, $flags = 0)
    {
        // INSERT INTO ... SELECT ..
        if (($this->getType() == 'INSERT' || $this->getType() == 'REPLACE') && (($column instanceof self && $column->getType() == 'SELECT') || (is_string($column) && !isset($value) && QuerySplitter::getQueryType($column) == 'SELECT'))) {
            $this->setPart('query', $column, $flags);
            return $this;
        }


        $empty = ($this->getType() == 'INSERT' || $this->getType() == 'REPLACE') ? 'DEFAULT' : 'NULL';

        if (is_array($column)) {
            if ($flags & self::SET_EXPRESSION) {
                foreach ($column as $key => &$val) {
                    $kv = strpos($key, '=') !== false;
                    $val = QuerySplitter::backquote($key, $kv ? $flags : $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT) . ($kv ? '' : ' = ' . QuerySplitter::mapIdentifiers($val, $flags));
                }
            } else {
                foreach ($column as $key => &$val) {
                    $kv = strpos($key, '=') !== false;
                    $val = QuerySplitter::backquote($key, $kv ? $flags : $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT) . ($kv ? '' : ' = ' . QuerySplitter::quote($val));
                }
            }

            $column = join(', ', $column);
        } else {
            $kv = strpos($column, '=') !== false;
            $column = QuerySplitter::backquote($column, $kv ? $flags : $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT)
                    . ($kv ? '' : ' = ' . ($flags & self::SET_EXPRESSION ? QuerySplitter::backquote($value, $flags) : QuerySplitter::quote($value, $empty)));
        }

        $this->setPart('set', $column, $flags);

        return $this;
    }

    /**
     * Add a row of values to an "INSERT ... VALUES (...)" query statement.
     * 
     * @param mixed $values   Statement (string) or array of values or array with rows
     * @param int   $flags    Options as bitset
     * @return Query  $this
     */
    public function values($values, $flags = 0)
    {
        if (is_array($values) && is_array(reset($values))) {
            if ($flags & self::REPLACE) $this->setPart('values', $values, $flags);

            foreach ($values as &$row) {
                $this->values($row);
            }
        }

        if (is_array($values)) {
            foreach ($values as &$value) $value = QuerySplitter::quote($value, 'DEFAULT');
            $values = join(', ', $values);
        }

        $this->setPart('values', $values, $flags);

        return $this;
    }

    /**
     * Add criteria as WHERE expression to query statement.
     * 
     * @example $query->where('foo', 10);                                      // WHERE `foo` = 10 
     * @example $query->where('foo > ?', 10);                                  // WHERE `foo` > 10
     * @example $query->where('foo IS NULL');                                  // WHERE `foo` IS NULL
     * @example $query->where('foo', array(10, 20));                           // WHERE `foo` IN (10, 20)
     * @example $query->where('foo BETWEEN ? AND ?', array(10, 20));           // WHERE `foo` BETWEEN 10 AND 20
     * @example $query->where('bar LIKE %?%', "blue");                         // WHERE `bar` LIKE "%blue%"
     * @example $query->where('foo = ? AND bar LIKE %?%', array(10, "blue"));  // WHERE `foo` = 10 AND `bar` LIKE "%blue%"
     * @example $query->where(array('foo'=>10, 'bar'=>"blue"));                // WHERE `foo` = 10 AND `bar` = "blue"
     * 
     * @param mixed $column  Expression, column name, column number, expression with placeholders or array(column=>value, ...)
     * @param mixed $value   Value or array of values
     * @param int   $flags   Query::REPLACE, Query::PREPEND or Query::APPEND + Query::BACKQUOTE_%
     * @return Query  $this
     */
    public function where($column, $value = null, $flags = 0)
    {
        $where = QuerySplitter::buildWhere($column, $value, $flags);
        if (isset($where)) $this->setPart('where', $where, $flags);

        return $this;
    }

    /**
     * Add criteria as HAVING expression to query statement.
     * @see Query::where()
     * 
     * @param mixed $column  Expression, column name, column number, expression with placeholders or array(column=>value, ...)
     * @param mixed $value   Value or array of values
     * @param int   $flag    Query::REPLACE, Query::PREPEND or Query::APPEND + Query::BACKQUOTE_%
     * @return Query  $this
     */
    public function having($column, $value = null, $flags = 0)
    {
        $where = QuerySplitter::buildWhere($column, $value, $flags);
        if (isset($where)) $this->setPart('having', $where, $flags);

        return $this;
    }

    /**
     * Add GROUP BY expression to query statement.
     *
     * @param string|array $column  GROUP BY expression (string) or array with columns
     * @param int          $flags   Query::REPLACE, Query::PREPEND or Query::APPEND + Query::BACKQUOTE_%
     * @return Query  $this
     */
    public function groupBy($column, $flags = 0)
    {
        if (is_scalar($column)) {
            $column = QuerySplitter::backquote($column, $flags);
        } else {
            foreach ($column as &$col) $col = QuerySplitter::backquote($col, $flags);
            $column = join(', ', $column);
        }

        $this->setPart('group by', $column, $flags);

        return $this;
    }

    /**
     * Add ORDER BY expression to query statement.
     *
     * @param mixed $column  ORDER BY expression (string) or array with columns
     * @param int   $flags   Query::ASC or Query::DESC + Query::REPLACE, Query::PREPEND (default) or Query::APPEND + Query::BACKQUOTE_%.
     * @return Query  $this
     */
    public function orderBy($column, $flags = 0)
    {
        if (!is_array($column)) $column = array($column);

        foreach ($column as &$col) {
            $col = QuerySplitter::backquote($col, $flags);

            if ($flags & self::DESC) $col .= ' DESC';
            elseif ($flags & self::ASC) $col .= ' ASC';
        }
        $column = join(', ', $column);

        if (!($flags & self::APPEND)) $flags |= self::PREPEND;
        $this->setPart('order by', $column, $flags);

        return $this;
    }

    /**
     * Add ON DUPLICATE KEY UPDATE to an INSERT query.
     * 
     * @param mixed $column      Column name, array(column, ...) or array('column' => expression, ...)
     * @param mixed $expression  Expression or value  
     * @param int   $flags       Query::SET_VALUE, Query::SET_EXPRESSION (default) + Query::REPLACE, Query::PREPEND or Query::APPEND (default) + Query::BACKQUOTE_%
     * @return Query  $this
     */
    public function onDuplicateKeyUpdate($column = true, $expression = null, $flags = 0)
    {
        if (is_array($column)) {
            foreach ($column as $key => &$val) {
                if (is_int($key)) {
                    $val = QuerySplitter::backquote($val, $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT);
                    $val .= " = VALUES($val)";
                } else {
                    $val = QuerySplitter::backquote($key, $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT)
                            . ' = ' . ($flags & self::SET_VALUE ? QuerySplitter::quote($val) : QuerySplitter::backquote($val, $flags));
                }
            }
            $column = join(', ', $column);
        } elseif ($column !== true) {
            $column = QuerySplitter::backquote($column, $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT);

            if (!isset($expression)) $column .= " = VALUES($column)";
            elseif ($flags & self::SET_VALUE) $column .= ' = ' . QuerySplitter::quote($value, 'DEFAULT');
            else $column .= ' = ' . QuerySplitter::mapIdentifiers($value, $flags);
        }

        $this->setPart('on duplicate key update', $column, $flags);

        return $this;
    }

    /**
     * Set the limit for the number of rows returned when excecuted.
     *
     * @param int|string $rowcount  Number of rows of full limit statement
     * @param int        $offset    Start at row
     * @return Query  $this
     */
    public function limit($rowcount, $offset = null)
    {
        $this->setPart('limit', $rowcount . (isset($offset) ? " OFFSET $offset" : ""), self::REPLACE);
        return $this;
    }

    /**
     * Set the limit by specifying the page.
     *
     * @param int $page      Page numer, starts with page 1
     * @param int $rowcount  Number of rows per page
     * @return Query  $this
     */
    public function page($page, $rowcount = null)
    {
        if (!isset($rowcount)) {
            $limit = $this->getPart('limit');
            if (strpos($limit, ',') !== false) $limit = substr($limit, strpos($limit, ',') + 1);

            $rowcount = (int)trim($limit);
            if (!$rowcount) return $this;
        }

        return $this->limit($rowcount, $rowcount * ($page - 1));
    }

    /**
     * Set the options part of a query.
     *
     * @param string $options
     * @param int    $flags
     * @return Query  $this
     */
    public function options($options, $flags = 0)
    {
        $this->setPart('options', $options, $flags);
        return $this;
    }

    /**
     * Magic method to build a query from scratch.
     * 
     * @param string $type
     * @param array  $args
     * @return Query
     */
    public static function __callStatic($type, $args)
    {
        if (QuerySplitter::getQueryType($type) == null) throw new \Exception("Unknown query type '$type'.");

        list($expression, $flags) = $args + array(null, 0);

        if (is_array($expression)) {
            if ($flags & Query::_BACKQUOTE_OPTIONS) {
                foreach ($expression as &$field) {
                    $field = QuerySplitter::backquote($field, $flags);
                }
            }
            $expression = join(', ', $expression);
        } else {
            if ($flags & Query::_BACKQUOTE_OPTIONS) $expression = QuerySplitter::backquote($expression, $flags);
        }

        return new self($type . (isset($expression) ? " $expression" : ''));
    }

    /**
     * Get a query to count the number of rows that the resultset would contain.
     * 
     * @param int $flags  Query::ALL_ROWS
     * @return Query
     */
    public function count($flags = 0)
    {
        $statement = QuerySplitter::buildCountQuery($this->getParts(), $flags);
        return new self($statement);
    }

    /**
     * Quote a value so it can be savely used in a query.
     * 
     * @param mixed  $value
     * @param string $empty  Return $empty if $value is null
     * @return string
     */
    public static function quote($value, $empty = 'NULL')
    {
        return QuerySplitter::quote($value, $empty);
    }

    /**
     * Quotes a string so it can be used as a table or column name.
     * Dots are seen as seperator and are kept out of quotes.
     * 
     * Doesn't quote expressions without Query::BACKQUOTE_STRICT. This means it is not secure without this option. 
     * 
     * @param string   $identifier
     * @param int      $flags       Query::BACKQUOTE_%, defaults to Query::BACKQUOTE_SMART
     * @return string
     */
    public static function backquote($identifier, $flags = 0)
    {
        return QuerySplitter::backquote($identifier, $flags);
    }

    /**
     * Insert parameters into SQL query.
     * Don't mix unnamed ('?') and named (':key') placeholders.
     *
     * @param mixed $statement  Query string or Query::Statement object
     * @param array $params     Parameters to insert into statement on placeholders
     * @return mixed
     */
    public static function bind($statement, $params)
    {
        if (!is_array($params) || is_int(key($params))) {
            $params = func_get_args();
            $params = array_splice($params, 1);
        }
        
        return QuerySplitter::bind($statement, $params);
    }
}

