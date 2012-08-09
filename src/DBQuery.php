<?php

/**
 * Query builder for MySQL query statements.
 * All editing statements support fluent interfaces.
 * 
 * @package DBQuery
 */
class DBQuery
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
	 * @var DBQuery[]
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
	 * Class constructor
	 *
	 * @param string $statement  Query statement
	 */
	public function __construct($statement)
	{
		$this->statement = $statement;
	}
	
    
    //------------- Splitting -------------
    
	/**
     * Return the type of the query
     *
     * @param int $subset
     * @return string
     */
	public function getType()
	{
		if (!isset($this->queryType)) $this->queryType = DBQuery_Splitter::getQueryType($this->statement);
		return $this->queryType;
	}

	/**
     * Return the statement without any added or replaced parts.
     *
     * @return DBQuery  $this
     */
   	public function getBaseStatement()
   	{
   		return new static($this->statement, $this);
   	}

	/**
	 * Cast statement object to string.
	 *
	 * @return string
	 */
	public function __toString()
	{
		if (empty($this->partsAdd) && empty($this->partsReplace)) return $this->statement;
		
		if (!isset($this->cachedStatement)) $this->cachedStatement = DBQuery_Splitter::join($this->getParts());
		return $this->cachedStatement;
	}

	/**
	 * Get a subquery (from base statement).
	 * 
	 * @param int $subset  Number of subquery (start with 1)
	 * @return DBQuery
	 */
	public function getSubquery($subset=1)
	{
		if (!isset($this->subqueries)) { 
			$statements = DBQuery_Splitter::extractSubsets($this->statement);
			$this->baseParts = DBQuery_Splitter::split($statements[0]);
			unset($statements[0]);
			
			foreach ($statements as $i=>$statement) $this->subqueries[$i] = new static($statement, $this);
		}
			
		if (!isset($this->subqueries[$subset])) throw new Exception("Unable to get subquery #$subset: Query only has " . count($this->subqueries) . (count($this->subqueries) == 1 ? " subquery." : " subqueries."));
		return $this->subqueries[$subset];
	}
	
    /**
     * Split the base statement
     * 
     * @return array
     */
    protected function getBaseParts()
    {
        if (!isset($this->baseParts)) $this->baseParts = DBQuery_Splitter::split($this->statement);
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
			$parts =  $this->getBaseParts();
			if (empty($this->partsAdd) && empty($this->partsReplace)) return $parts;
            
			if (!empty($this->partsReplace)) $parts = array_merge($parts, $this->partsReplace);
			if (!empty($this->partsAdd)) $parts = DBQuery_Splitter::addParts($parts, $this->partsAdd);
			if (key($parts) == 'select' && empty($parts['columns'])) $parts['columns'] = '*';
            
			if (isset($parts['on duplicate key update']) && trim($parts['on duplicate key update']) === '1') {
                $columns = DBQuery_Splitter::splitColumns($parts);
                foreach ($columns as &$column) {
                    $column = "$column = VALUES($column)";
                }
                $parts['on duplicate key update'] = join(', ', $columns);
            }
            
			$this->cachedParts =& $parts;
		}		
		
		return empty($this->subqueries) ? $this->cachedParts : DBQuery_Splitter::injectSubsets(array($this->cachedParts) + $this->subqueries);
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
	 * @param int $flags  DBQuery::SPLIT_% options
	 * @return DB_Table
	 */
	public function getTables($flags=0)
	{
		return DBQuery_Splitter::splitTables($this->getParts(), $flags);
	}
	
	/**
	 * Get the columns used in the statement.
	 * 
	 * @param int $flags  DBQuery::SPLIT_% and DBQuery::UNQUOTE options
	 * @return array
	 */
	public function getColumns($flags=0)
	{
		return DBQuery_Splitter::splitColumns($this->getParts(), $flags);
	}

	/**
	 * Get the values used in the statement.
	 * Only for INSERT INTO ... VALUES ... query.
	 * 
	 * @param int $flags  Optional DBQuery::UNQUOTE
	 * @return array
	 */
	public function getValues($flags=0)
	{
		return DBQuery_Splitter::splitValues($this->getParts(), $flags);
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
	 * @param int    $flags      DBQuery::APPEND (default), DBQuery::PREPEND or DBQuery::REPLACE + DBQuery::BACKQUOTE_%
	 */
	protected function setPart($part, $expression, $flags=DBQuery::APPEND)
	{
		$part = strtolower($part);
        
        if (!array_key_exists($part, $this->getBaseParts())) throw new Exception("A " . $this->getType() . " query doesn't have a $part part");
        
		$this->clearCachedStatement();
		
        if ($flags & (self::APPEND | self::PREPEND | self::REPLACE) == 0) $flags |= self::REPLACE;
                
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
	 * @param int    $flags     DBQuery::REPLACE, DBQuery::PREPEND or DBQuery::APPEND + DBQuery::BACKQUOTE_% options as bitset.
	 * @return DBQuery  $this
	 */
    protected function addTable($table, $joinType=null, $joinOn=null, $flags=0)
    {
   		switch ($this->getType()) {
   			case 'INSERT':	$part = 'into'; break;
   			case 'UPDATE':	$part = 'table'; break;
   			default:		$part = 'from';
   		}
   		
        if (!($flags & self::_BACKQUOTE_OPTIONS)) $flags |= self::BACKQUOTE_WORDS;
        if (!($flags & self::_PLACEMENT_OPTIONS)) $flags |= $joinType ? self::APPEND : self::REPLACE;
        if (!isset($joinType) && ~$flags & self::REPLACE) $joinType = ',';
   		
        $table = DBQuery_Splitter::backquote($table, $flags);
        $joinOn = DBQuery_Splitter::backquote($joinOn, $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_SMART);
        
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
	 * @param int    $flags  DBQuery::BACKQUOTE_% options as bitset.
	 * @return DBQuery  $this
	 */
	public function from($table, $flags=0)
   	{
        return $this->addTable($table, null, null, $flags);
   	}
    
	/**
	 * Set the table of an UPDATE query.
     * 
	 * @param string $table    tablename
	 * @param int    $flags    DBQuery::REPLACE, DBQuery::PREPEND or DBQuery::APPEND + DBQuery::BACKQUOTE_% options as bitset.
	 * @return DBQuery  $this
	 */
    public function table($table, $flags=0)
    {
        return $this->addTable($table, null, null, $flags);
    }
	
	/**
	 * Set the INTO table of an INSERT query.
     * 
	 * @param string $table    tablename
	 * @param int    $flags    DBQuery::REPLACE, DBQuery::PREPEND or DBQuery::APPEND + DBQuery::BACKQUOTE_% options as bitset.
	 * @return DBQuery  $this
	 */
    public function into($table, $flags=0)
    {
        return $this->addTable($table, null, null, $flags);
    }
	
	/**
	 * Add an inner join to the query.
     * 
	 * @param string $table  tablename
	 * @param int    $flags  DBQuery::BACKQUOTE_% options as bitset.
	 * @return DBQuery  $this
	 */
	public function innerJoin($table, $on=null, $flags=0)
   	{
        return $this->addTable($table, "INNER JOIN", $on, $flags);
   	}
    
	/**
	 * Add an inner join to the query.
     * 
	 * @param string $table  tablename
	 * @param int    $flags  DBQuery::BACKQUOTE_% options as bitset.
	 * @return DBQuery  $this
	 */
	public function leftJoin($table, $on, $flags=0)
   	{
        return $this->addTable($table, "LEFT JOIN", $on, $flags);
   	}
    
	/**
	 * Add an inner join to the query.
     * 
	 * @param string $table  tablename
	 * @param int    $flags  DBQuery::BACKQUOTE_% options as bitset.
	 * @return DBQuery  $this
	 */
	public function rightJoin($table, $on, $flags=0)
   	{
        return $this->addTable($table, "RIGHT JOIN", $on, $flags);
   	}
    
    
    /**
   	 * Add column(s) to query statement.
   	 * 
   	 * Flags:
   	 *  Position:   DBQuery::REPLACE, DBQuery::PREPEND or DBQuery::APPEND (default)
   	 *  Quote expr: DBQuery::BACKQUOTE_%
	 *
	 * @param mixed $column  Column name or array(column, ...)
	 * @param int   $flags   Options as bitset
	 * @return DBQuery  $this
   	 */
   	public function columns($column, $flags=0)
   	{
   		if (is_array($column)) {
            foreach ($column as $key=>&$col) {
                $col = DBQuery_Splitter::backquote($col, $flags) . (!is_int($key) ? ' AS ' .  DBQuery_Splitter::backquote($key, DBQuery::BACKQUOTE_STRICT) : '');
            }
   			
   			$column = join(', ', $column);
   		} else {
            $column = DBQuery_Splitter::backquote($column, $flags);
        }
   		
		$this->setPart('columns', $column, $flags);
        
		return $this;
   	}

    /**
   	 * Alias of DBQuery::columns().
	 *
	 * @param mixed $column  Column name or array(column, ...)
	 * @param int   $flags   Options as bitset
	 * @return DBQuery  $this
   	 */
   	public function column($column, $flags=0)
   	{
        return $this->columns($column, $flags);
    }
    
   	/**
   	 * Add an expression to the SET part of an INSERT SET ... or UPDATE SET query
   	 * 
   	 * Flags:
   	 *  Position:   DBQuery::REPLACE, DBQuery::PREPEND or DBQuery::APPEND (default)
   	 *  Set:        DBQuery::SET_EXPRESSION or DBQuery::SET_VALUE (default)
   	 *  Quote expr: DBQuery::BACKQUOTE_%
	 *
     * For an INSERT INTO ... SELECT query $column should be a DBQuery object
     * 
     * @param string|array $column  Column name or array(column => value, ...)
	 * @param mixed        $value   Value or expression (omit if $column is an array)
	 * @param int          $flags   Options as bitset
	 * @return DBQuery  $this
   	 */
    public function set($column, $value=null, $flags=0)
    {
        // INSERT INTO ... SELECT ..
        if ($this->getType() == 'INSERT' && $column instanceof self && $column->getType() == 'SELECT') {
            $this->setPart('query', $column);
            return $this;
        }
        
        
   		$empty = $this->getType() == 'INSERT' ? 'DEFAULT' : 'NULL';
   		
   		if (is_array($column)) {
            if ($flags & self::SET_EXPRESSION) {
                foreach ($column as $key=>&$val) {
                    $kv = strpos($key, '=') !== false;
                    $val = DBQuery_Splitter::backquote($key, $kv ? $flags : $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT) . ($kv ? '' : ' = ' . DBQuery_Splitter::mapIdentifiers($val, $flags));
                }
   			} else {
                foreach ($column as $key=>&$val) {
                    $kv = strpos($key, '=') !== false;
                    $val = DBQuery_Splitter::backquote($key, $kv ? $flags : $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT) . ($kv ? '' : ' = ' . DBQuery_Splitter::quote($val));
                }
   			}
   			
   			$column = join(', ', $column);
   		} else {
            $kv = strpos($column, '=') !== false;
            $column = DBQuery_Splitter::backquote($column, $kv ? $flags : $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT)
              . ($kv ? '' : ' = ' . ($flags & self::SET_EXPRESSION ? DBQuery_Splitter::backquote($value, $flags) : DBQuery_Splitter::quote($value, $empty)));
        }
   		
		$this->setPart('set', $column, $flags);
        
		return $this;
    }
    
   	/**
   	 * Add a row of values to an "INSERT ... VALUES (...)" query statement.
   	 * 
	 * @param mixed $values   Statement (string) or array of values or array with rows
	 * @param int   $flags    Options as bitset
	 * @return DBQuery  $this
   	 */
   	public function values($values, $flags=0)
   	{
        if (is_array($values) && is_array(reset($values))) {
            if ($flags & self::REPLACE) $this->setPart('values', $values, $flags);
            
            foreach ($values as &$row) {
                $this->values($row);
            }
        }
        
   		if (is_array($values)) {
   			foreach ($values as &$value) $value = DBQuery_Splitter::quote($value, 'DEFAULT');
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
	 * @param int   $flags   DBQuery::REPLACE, DBQuery::PREPEND or DBQuery::APPEND + DBQuery::BACKQUOTE_%
	 * @return DBQuery  $this
	 */
	public function where($column, $value=null, $flags=0)
	{
        $where = DBQuery_Splitter::buildWhere($column, $value, $flags);
		if (isset($where)) $this->setPart('where', $where, $flags);
		
		return $this;
	}
	
	/**
	 * Add criteria as HAVING expression to query statement.
     * @see DBQuery::where()
	 * 
	 * @param mixed $column  Expression, column name, column number, expression with placeholders or array(column=>value, ...)
	 * @param mixed $value   Value or array of values
	 * @param int   $flag    DBQuery::REPLACE, DBQuery::PREPEND or DBQuery::APPEND + DBQuery::BACKQUOTE_%
	 * @return DBQuery  $this
	 */
	public final function having($column, $value=null, $flags=0)
	{
        $where = DBQuery_Splitter::buildWhere($column, $value, $flags);
		if (isset($where)) $this->setPart('having', $where, $flags);
		
		return $this;
	}
	
	/**
	 * Add GROUP BY expression to query statement.
	 *
	 * @param string|array $column  GROUP BY expression (string) or array with columns
	 * @param int          $flags   DBQuery::REPLACE, DBQuery::PREPEND or DBQuery::APPEND + DBQuery::BACKQUOTE_%
	 * @return DBQuery  $this
	 */
	public function groupBy($column, $flags=0)
	{
		if (is_scalar($column)) {
			$column = DBQuery_Splitter::backquote($column, $flags);
		} else {
			foreach ($column as &$col) $col = DBQuery_Splitter::backquote($col, $flags);
			$column = join(', ', $column);
		}
		
 		$this->setPart('group by', $column, $flags);
        
		return $this;
	}

	/**
	 * Add ORDER BY expression to query statement.
	 *
	 * @param mixed $column  ORDER BY expression (string) or array with columns
	 * @param int   $flags   DBQuery::ASC or DBQuery::DESC + DBQuery::REPLACE, DBQuery::PREPEND (default) or DBQuery::APPEND + DBQuery::BACKQUOTE_%.
	 * @return DBQuery  $this
	 */
	public function orderBy($column, $flags=0)
	{
        if (!is_array($column)) $column = array($column);
        
        foreach ($column as &$col) {
            $col = DBQuery_Splitter::backquote($col, $flags);
            
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
     * @param int   $flags       DBQuery::SET_VALUE, DBQuery::SET_EXPRESSION (default) + DBQuery::REPLACE, DBQuery::PREPEND or DBQuery::APPEND (default) + DBQuery::BACKQUOTE_%
     * @return DBQuery  $this
     */
    public function onDuplicateKeyUpdate($column=true, $expression=null, $flags=0)
    {
   		if (is_array($column)) {
            if (is_int(key($column))) {
                foreach ($column as &$col) {
                    $col = DBQuery_Splitter::backquote($key, $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT);
                    $col .= " = VALUES($col)";
                }
   			} elseif ($flags & self::SET_VALUE) {
                foreach ($column as $key=>&$val) {
                    $val = DBQuery_Splitter::backquote($key, $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT) . ' = ' . DBQuery_Splitter::quote($val);
                }
            } else {
                foreach ($column as $key=>&$val) {
                    $val = DBQuery_Splitter::backquote($key, $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT) . ' = ' . DBQuery_Splitter::mapIdentifiers($val, $flags);
                }
            }
   		} elseif ($column !== true) {
            $column = DBQuery_Splitter::backquote($column, $flags & ~self::_BACKQUOTE_OPTIONS | self::BACKQUOTE_STRICT);
            
            if (!isset($expression)) $column .= " = VALUES($column)";
              elseif ($flags & self::SET_VALUE) $column .= ' = ' . DBQuery_Splitter::quote($value, 'DEFAULT');
              else $column .= ' = ' . DBQuery_Splitter::mapIdentifiers($value, $flags);
        }
        
        $this->setPart('on duplicate key update', $column, $flags);
        
        return $this;
    }


    /**
	 * Set the limit for the number of rows returned when excecuted.
	 *
	 * @param int|string $rowcount  Number of rows of full limit statement
	 * @param int        $offset    Start at row
	 * @return DBQuery  $this
	 */
	public function limit($rowcount, $offset=null)
	{
		$this->setPart('limit', $rowcount . (isset($offset) ? " OFFSET $offset" : ""), self::REPLACE);
		return $this;
	}

	/**
	 * Set the limit by specifying the page.
	 *
	 * @param int $page      Page numer, starts with page 1
	 * @param int $rowcount  Number of rows per page
	 * @return DBQuery  $this
	 */
	public function page($page, $rowcount)
	{
		return $this->limit($rowcount, $rowcount * ($page-1));
	}


	/**
	 * Set the options part of a query.
	 *
	 * @param string $options
     * @param int    $flags
	 * @return DBQuery  $this
	 */
	public function options($options, $flags=0)
	{
		$this->setPart('options', $options, $flags);
        return $this;
	}
    
    
	/**
	 * Magic method to build a query from scratch.
	 * 
     * @param string $type
     * @param array  $args
	 * @return DBQuery
	 */
    public static function __callStatic($type, $args)
    {
        if (DBQuery_Splitter::getQueryType($type) == null) throw new Exception("Unknown query type '$type'.");

        list($expression, $flags) = $args + array(null, 0);
        
        if (is_array($expression)) {
            if ($flags & DBQuery::_BACKQUOTE_OPTIONS) {
                foreach ($expression as &$field) {
                    $field = DBQuery_Splitter::backquote($field, $flags);
                }
            }
            $expression = join(', ', $expression);
        } else {
            if ($flags & DBQuery::_BACKQUOTE_OPTIONS) $expression = DBQuery_Splitter::backquote($expression, $flags);
        }
        
        return new self($type . (isset($expression) ? " $expression" : ''));
    }
    
    
   	/**
     * Get a query to count the number of rows that the resultset would contain.
     * 
     * @param int $flags  DBQuery::ALL_ROWS
     * @return DBQuery
     */
   	public function count($flags=0)
   	{
        $statement = DBQuery_Splitter::buildCountQuery($this->getParts(), $flags);
        return new self($statement);
   	}
    
    
	/**
	 * Quote a value so it can be savely used in a query.
	 * 
	 * @param mixed  $value
	 * @param string $empty  Return $empty if $value is null
	 * @return string
	 */
	public static function quote($value, $empty='NULL')
	{
        return DBQuery_Splitter::quote($value, $empty);
    }

    /**
	 * Quotes a string so it can be used as a table or column name.
	 * Dots are seen as seperator and are kept out of quotes.
	 * 
	 * Doesn't quote expressions without DBQuery::BACKQUOTE_STRICT. This means it is not secure without this option. 
	 * 
	 * @param string   $identifier
	 * @param int      $flags       DBQuery::BACKQUOTE_%, defaults to DBQuery::BACKQUOTE_SMART
	 * @return string
	 */
	public static function backquote($identifier, $flags=0)
	{
        return DBQuery_Splitter::backquote($identifier, $flags);
    }    
}
