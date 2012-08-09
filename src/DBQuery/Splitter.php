<?php

/**
 * Break down a mysql query statement to different parts, which can be altered and joined again.
 * Supported types: SELECT, INSERT, REPLACE, UPDATE, DELETE, TRUNCATE.
 *
 * SELECT ... UNION syntax is *not* supported.
 * DELETE ... USING syntax is *not* supported.
 * Invalid query statements might give unexpected results. 
 * 
 * All methods of this class are static.
 * 
 * { @internal
 *   This class highly depends on complicated PCRE regular expressions. So if your not really really really good at reading/writing these, don't touch this class.
 *   To prevent a regex getting in some crazy (or catastrophic) backtracking loop, use regexbuddy (http://www.regexbuddy.com) or some other step-by-step regex debugger.
 *   The performance of each function is really important, since these functions will be called a lot in 1 page and should be concidered abstraction overhead. The focus is on performance not readability of the code.
 * 
 *   Expression REGEX_VALUES matches all quoted strings, all backquoted identifiers and all words and all non-word chars upto the next keyword.
 *   It uses atomic groups to look for the next keyword after each quoted string and complete word, not after each char. Atomic groups are also neccesary to prevent catastrophic backtracking when the regex should fail.
 * 
 *   Expressions like '/\w+\s*(abc)?\s*\w+z/' should be prevented. If this regex would try to match "ef    ghi", the regex will first take all 3 spaces for the first \s*. When the regex fails it retries taking the
 *     first 2 spaces for the first \s* and the 3rd space for the second \s*, etc, etc. This causes the matching to take more than 3 times as long as '/\w+\s*(abc\s*)?\w+z/' would.
 *   This is the reason why trailing spaces are included with REGEX_VALUES and not automaticly trimmed.
 * }}
 * 
 * @package DBQuery
 * 
 * @todo It might be possible to use recursion instead of extracting subqueries, using \((SELECT\b)(?R)\). For query other that select, I should do (?:^\s++UPDATE ...|(?<!^)\s++SELECT ...) to match SELECT and not UPDATE statement in recursion.
 * @todo Implement splitValues to get values of INSERT INTO ... VALUES ... statement
 */
class DBQuery_Splitter
{
	const REGEX_VALUES = '(?:\w++|`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|\s++|[^`"\'\w\s])*?';
	const REGEX_IDENTIFIER = '(?:(?:\w++|`[^`]*+`)(?:\.(?:\w++|`[^`]*+`)){0,2})';
	const REGEX_QUOTED = '(?:`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\')';
	
	//------------- Basics -----------------------
	
	/**
	 * Quote a value so it can be savely used in a query.
	 * 
	 * @param mixed  $value
	 * @param string $empty  Return $empty if $value is null
	 * @return string
	 */
	public static function quote($value, $empty='NULL')
	{
		if (is_null($value)) return $empty;
		if (is_bool($value)) return $value ? 'TRUE' : 'FALSE';
		if (is_int($value) || is_float($value)) return (string)$value;
		
		if (is_array($value)) {
			foreach ($value as &$v) $v = self::quote($v, $empty);
			return '(' . join(', ', $value) . ')';
		}
		
		return '"' . strtr($value, array('\\'=>'\\\\', "\0"=>'\\0', "\r"=>'\\r', "\n"=>'\\n', '"'=>'\\"')) . '"';
	}
	
    
    /**
	 * Quotes a string so it can be used as a table or column name.
	 * Dots are seen as seperator and are kept out of quotes.
	 * 
	 * Doesn't quote expressions without DBQuery::BACKQUOTE_STRICT. This means it is not secure without this option. 
	 * 
	 * @param string   $identifier
	 * @param int      $flags       DBQuery::BACKQUOTE_%
	 * @return string
	 * 
	 * @todo Cleanup misquoted TRIM function
	 */
	public static function backquote($identifier, $flags=0)
	{
		// Strict
		if ($flags & DBQuery::BACKQUOTE_STRICT) {
			$identifier = trim($identifier);
			if (preg_match('/^\w++$/', $identifier)) return "`$identifier`";
			
			$quoted = preg_replace_callback('/`[^`]*+`|([^`\.]++)/', array('DBQuery_Splitter', 'backquote_ab'), $identifier);
			
			if ($quoted && !preg_match('/^(?:`[^`]*`\.)*`[^`]*`$/', $quoted)) throw new Exception("Unable to quote '$identifier' safely");
			return $quoted;
		}
		
		// None
		if (($flags & DBQuery::_BACKQUOTE_OPTIONS) == DBQuery::BACKQUOTE_NONE) {
		 	return $identifier;
		}

        // Words
		if ($flags & DBQuery::BACKQUOTE_WORDS) {
            $quoted = preg_replace_callback('/"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|(?<=^|[\s,])(?:NULL|TRUE|FALSE|DEFAULT|DIV|AND|OR|XOR|IN|IS|BETWEEN|R?LIKE|REGEXP|SOUNDS\s+LIKE|MATCH|AS|CASE|WHEN|ASC|DESC|BINARY)(?<=^|[\s,])|(?<=^|[\s,])COLLATE\s+\w++|(?<=^|[\s,])USING\s+\w++|`[^`]*+`|([^\s,\.`\'"]*[a-z_][^\s,\.`\'"]*)/i', array('DBQuery_Splitter', 'backquote_ab'), $identifier);
            return $quoted;
		}
        
        // Smart
		$quoted = preg_replace_callback('/"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|\b(?:NULL|TRUE|FALSE|DEFAULT|DIV|AND|OR|XOR|IN|IS|BETWEEN|R?LIKE|REGEXP|SOUNDS\s+LIKE|MATCH|AS|CASE|WHEN|ASC|DESC|BINARY)\b|\bCOLLATE\s+\w++|\bUSING\s+\w++|TRIM\s*\((?:BOTH|LEADING|TRAILING)|`[^`]*+`|(\d*[a-z_]\w*\b)(?!\s*\()/i', array('DBQuery_Splitter', 'backquote_ab'), $identifier);
		if (preg_match('/\bCAST\s*\(/i', $quoted)) $quoted = self::backquote_castCleanup($quoted);
        return $quoted;
	}
	
    /**
     * Callback function for backquote.
	 * @ignore
     * 
     * @param array $match
     * @return string
     */
    protected static function backquote_ab($match)
    {
        return !empty($match[1]) ? '`' . $match[1] . '`' : $match[0];
    }

	/**
	 * Unquote up quoted types of CAST function.
	 * @ignore
	 * 
	 * @param string|array $match  Match or identifier
	 * @return string  
	 */
	protected static function backquote_castCleanup($match)
	{
		if (is_array($match) && !isset($match[2])) return $match[0];
		if (!is_array($match)) $match = array(2=>$match);
		
		$match[2] = preg_replace_callback('/((?:' . self::REGEX_QUOTED . '|[^()`"\']++)*)(?:\(((?R)*)\))?/i', array(__CLASS__, 'backquote_castCleanup'), $match[2]);
		if (!empty($match[1]) && preg_match('/\CAST\s*$/i', $match[1])) $match[2] = preg_replace('/(\bAS\b\s*)`([^`]++)`(\s*)$/i', '\1\2\3', $match[2]);
		
		return isset($match[0]) ? "{$match[1]}({$match[2]})" : $match[2]; 
	}
	
	/**
	 * Check if expression is a field/table name
	 *
	 * @param string $name
	 * @return boolean
	 */
	public static function isIdentifier($name)
	{
		return (bool)preg_match('/^((?:`([^`]*)`|(\d*[a-z_]\w*))\.)*(`([^`]*)`|(\d*[a-z_]\w*))$/i', trim($name));
	}
    
	/**
     * Insert parameters into SQL query.
     * Don't mix unnamed ('?') and named (':key') placeholders.
	 *
	 * @param mixed $statement  Query string or DBQuery::Statement object
	 * @param array $params     Parameters to parse into statement on placeholders
	 * @return mixed
	 */
	public static function parse($statement, $params)
	{
        $fn = function ($match) use (&$params) {
            if (!empty($match[2]) && !empty($params)) $value = array_shift($params);
              elseif (!empty($match[3]) && array_key_exists($match[3], $params)) $value = $params[$match[3]];
              else return $match[0];
              
             if (isset($value) && ($match[1] || $match[4])) $value = $match[1] . $value . $match[4];
             return DBQuery_Splitter::quote($value);
        };
		
		return preg_replace_callback('/`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|(%?)(?:(\?)|:(\w++))(%?)/', $fn, $statement);
	}
	
	/**
	 * Count the number of placeholders in a statement.
	 *
	 * @param string $statement
	 * @return int
	 */
	public static function countPlaceholders($statement)
	{
		$matches = null;
		if (!preg_match_all('/`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|(\?|:\w++)/', $statement, $matches, PREG_PATTERN_ORDER)) return 0;
        
		return count(array_filter($matches[1]));
	}
		
	
	//------------- Split / Build query -----------------------

	/**
	 * Return the type of the query.
	 *
	 * @param string $sql  SQL query statement (or an array with parts)
	 * @return string
	 */
	public static function getQueryType($sql)
	{
		if (is_array($sql)) $sql = key($sql);
		
		$matches = null;
		if (!preg_match('/^\s*(SELECT|INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|CALL|DO|HANDLER|LOAD\s+(?:DATA|XML)\s+INFILE|(?:ALTER|CREATE|DROP|RENAME)\s+(?:DATABASE|TABLE|VIEW|FUNCTION|PROCEDURE|TRIGGER|INDEX)|PREPARE|EXECUTE|DEALLOCATE\s+PREPARE|DESCRIBE|EXPLAIN|HELP|USE|LOCK\s+TABLES|UNLOCK\s+TABLES|SET|SHOW|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE SAVEPOINT|CACHE\s+INDEX|FLUSH|KILL|LOAD|RESET|PURGE\s+BINARY\s+LOGS|START\s+SLAVE|STOP\s+SLAVE)\b/si', $sql, $matches)) return null;
		
		$type = strtoupper(preg_replace('/\s++/', ' ', $matches[1]));
		if ($type === 'BEGIN') $type = 'START TRANSACTION';
		
		return $type;
	}
	
	/**
	 * Add parts to existing statement
	 * 
	 * @param array|string $sql  Parts (array) or statement (string)
	 * @param array        $add  Parts to add as array(key=>array(DBQuery::PREPEND=>array(), DBQuery::APPEND=>array(), ...)
	 * @return array|string
	 */
	public static function addParts($sql, $add)
	{
		if (is_array($sql)) $parts =& $sql;
		  else $parts = self::split($sql);;

		if (!empty($add)) {
			foreach ($add as $key=>&$partsAdd) {
				if (!empty($parts[$key])) $parts[$key] = trim($parts[$key]);
				
				if ($key === 'columns' || $key === 'set' || $key === 'group by' || $key === 'order by') {
					$parts[$key] = join(', ', array_merge(isset($partsAdd[DBQuery::PREPEND]) ? $partsAdd[DBQuery::PREPEND] : array(), !empty($parts[$key]) ? array($parts[$key]) : array(), isset($partsAdd[DBQuery::APPEND]) ? $partsAdd[DBQuery::APPEND] : array()));
				} elseif ($key === 'values') {
					$parts[$key] = (isset($partsAdd[DBQuery::PREPEND]) ? '(' . join('), (', $partsAdd[DBQuery::PREPEND]) . ')' : '') . (isset($partsAdd[DBQuery::PREPEND]) && !empty($parts[$key]) ? ', ' : '') . $parts[$key] . (isset($partsAdd[DBQuery::APPEND]) && !empty($parts[$key]) ? ', ' : '') .  (isset($partsAdd[DBQuery::APPEND]) ? '(' . join('), (', $partsAdd[DBQuery::APPEND]) . ')' : '');
				} elseif ($key === 'from' || $key === 'into' || $key === 'table') {
                    if (!empty($parts[$key]) && !preg_match('/^(\w+|`.*`)$/', $parts[$key])) $parts[$key] = '(' . $parts[$key] . ')';
					$parts[$key] = trim((isset($partsAdd[DBQuery::PREPEND]) ? join(' ', $partsAdd[DBQuery::PREPEND]) . ' ' : '') . (!empty($parts[$key]) ? $parts[$key] : '') . (isset($partsAdd[DBQuery::APPEND]) ? ' ' . join(' ', $partsAdd[DBQuery::APPEND]) : ''), ', ');
				} elseif ($key === 'where' || $key === 'having') {
					$items = array_merge(isset($partsAdd[DBQuery::PREPEND]) ? $partsAdd[DBQuery::PREPEND] : array(), !empty($parts[$key]) ? array($parts[$key]) : array(), isset($partsAdd[DBQuery::APPEND]) ? $partsAdd[DBQuery::APPEND] : array());
					if (!empty($items)) $parts[$key] = count($items) == 1 ? reset($items) : '(' . join(') AND (', $items) . ')';
				} else {
					$parts[$key] = (isset($partsAdd[DBQuery::PREPEND]) ? join(' ', $partsAdd[DBQuery::PREPEND]) . ' ' : '') . (!empty($parts[$key]) ? $parts[$key] : '') . (isset($partsAdd[DBQuery::APPEND]) ? ' ' . join(' ', $partsAdd[DBQuery::APPEND]) : '');
				}
			}
		}
		
		return $parts;
	}

	/**
     * Build a where expression.
     * 
	 * @param mixed $column Expression, column name, column number, expression with placeholders or array(column=>value, ...)
	 * @param mixed $value  Value or array of values
	 * @param int   $flags  DBQuery::BACKQUOTE_%
	 * @return string
	 */
	public static function buildWhere($column, $value=null, $flags=0)
	{
        // Build where for each column
		if (is_array($column)) {
			foreach ($column as $col=>&$value) {
				$value = self::buildWhere($col, $value);
                if (!isset($value)) unset($column[$col]);
			}
			
			return !empty($column) ? join(' AND ', $column) : null;
        }

        $placeholders = self::countPlaceholders($column);
        $column = self::backquote($column, $flags);
        
		// Simple case
        if ($placeholders == 0) {
            if (!isset($value) || $value === array()) return self::isIdentifier($column) ? null : $column;
            return $column . (is_array($value) ? ' IN ' : ' = ') . self::quote($value);
        }
        
        // With placeholder
        if ($placeholders == 1) $value = array($value);
        return self::parse($column, $value);
	}
	
	
	//------------- Extract subsets --------------------
	
	/**
	 * Extract subqueries from sql query (on for SELECT queries) and replace them with #subX in the main query.
	 * Returns array(main query, subquery1, [subquery2, ...])
	 *
	 * @param  string $sql
	 * @param  array  $sets  Do not use!
	 * @return array
	 * 
	 * @todo Extract subsets should only go 1 level deep
	 */
	public static function extractSubsets($sql, &$sets=null)
	{
		$ret_offset = isset($sets);
		$sets = (array)$sets;
		
		// There are certainly no subqueries
		if (stripos($sql, 'SELECT', 6) === false) {
			$offset = array_push($sets, $sql) - 1;
			return $ret_offset ? $offset : $sets;
		}

		// Extract any subqueries
		$offset = array_push($sets, null) - 1;
		
		if (self::getQueryType($sql) === 'INSERT') {
			$parts = self::split($sql);
			if (isset($parts['query'])) {
				self::extractSubsets($parts['query'], $sets);
				$parts['query'] = '#sub' . ($offset+1);
				$sql = self::join($parts);
			}
		}
		
		if (preg_match('/\(\s*SELECT\b/si', $sql)) {
			do {
				$matches = null;
				preg_match('/(?:`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|\((\s*SELECT\b.*\).*)|\w++|[^`"\'\w])*$/si', $sql, $matches, PREG_OFFSET_CAPTURE);
				if (isset($matches[1])) {
                    $fn = function($match) use(&$sets) { return '#sub' . DBQuery_Splitter::extractSubsets($match[0], $sets); };
                    $sql = substr($sql, 0, $matches[1][1]) . preg_replace_callback('/(?:`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|([^`"\'()]+)|\((?R)\))*/si', $fn, substr($sql, $matches[1][1]), 1);
                }
			} while (isset($matches[1]));
		}
		
		$sets[$offset] = $sql;
		return $ret_offset ? $offset : $sets;
	}
	
	/**
	 * Inject extracted subsets back into main sql query.
	 *
	 * @param array $sets  array(main query, subquery, ...) or array(main parts, subparts, ...); may be passed by reference
	 * @return string|array
	 */
	public static function injectSubsets($sets)
	{
		if (count($sets) == 1) return reset($sets);
		
		$done = false;
		$target =& $sets[min(array_keys($sets))];
		
		$fn = function($match) use(&$sets, &$done) {
			if (!empty($match[1])) $done = false;
			return empty($match[1]) ? $match[0] : (is_array($sets[$match[1]]) ? self::join($sets[$match[1]]) : $sets[$match[1]]);
		};
		
		while (!$done) {
			$done = true;
			$target = preg_replace_callback('/^' . self::REGEX_QUOTED . '|(?:\#sub(\d+))/', $fn, $target);
		}
		
		return $target;
	}

    
	//------------- Split query --------------------
	
	/**
	 * Split a query.
	 * If a part is not set whitin the SQL query, the part is an empty string.
	 *
	 * @param string $sql  SQL query statement
	 * @return array
	 */
	public static function split($sql)
	{
		$type = self::getQueryType($sql);
		switch ($type) {
			case 'SELECT':	 return self::splitSelectQuery($sql);
			case 'INSERT':
			case 'REPLACE':	 return self::splitInsertQuery($sql);
			case 'UPDATE':	 return self::splitUpdateQuery($sql);
			case 'DELETE':   return self::splitDeleteQuery($sql);
			case 'TRUNCATE': return self::splitTruncateQuery($sql);
			case 'SET':      return self::splitSetQuery($sql);
		}
		
		throw new Exception("Unable to split " . (!empty($type) ? "$type " : "") . "query. $sql");
	}

	/**
	 * Join parts to create a query.
	 * The parts are joined in the order in which they appear in the array.
	 * 
	 * CAUTION: The parts are joined blindly (no validation), so shit in shit out
	 *
	 * @param array $parts
	 * @return string
	 */
	public static function join($parts)
	{
        $type = self::getQueryType($parts);
        
		$sql_parts = array();
		
		foreach ($parts as $key=>&$part) {
            if (is_array($part)) $part = join(", ", $part);
            if ($part === '') $part = null;
            
			if (isset($part) || empty($sql_parts)) {
                if ($key == 'columns' && $type == 'INSERT') $part = '(' . $part . ')';
				$sql_parts[] .= ($key === 'columns' || $key === 'query' || $key === 'table' || $key === 'options' ? '' : strtoupper($key) . (isset($part) ? " " : "")) . trim($part, " \t\n,");
			} else {
                unset($sql_parts[$key]);
            }
		}

		return join(' ', $sql_parts);
	}

	/**
	 * Split select query.
	 * NOTE: Splitting a query with a subquery is considerably slower.
	 *
	 * @param string $sql  SQL SELECT query statement
	 * @return array
	 */
	protected static function splitSelectQuery($sql)
	{
		if (preg_match('/\(\s*SELECT\b/i', $sql)) {
			$sets = self::extractSubsets($sql);
			$sql = $sets[0];
		}

		$parts = null;
		if (!preg_match('/^\s*' .
		  'SELECT\b((?:\s+(?:ALL|DISTINCT|DISTINCTROW|HIGH_PRIORITY|STRAIGHT_JOIN|SQL_SMALL_RESULT|SQL_BIG_RESULT|SQL_BUFFER_RESULT|SQL_CACHE|SQL_NO_CACHE|SQL_CALC_FOUND_ROWS)\b)*)\s*(' . self::REGEX_VALUES . ')' .
		  '(?:' .
		  '(?:\bFROM\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bWHERE\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bGROUP\s+BY\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bHAVING\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bORDER\s+BY\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bLIMIT\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(\b(?:PROCEDURE|INTO|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s*MODE|CASCADE\s*ON)\b.*?)?' .
		  ')?' .
		  '(?:;|$)/si', $sql, $parts))
        {
            throw new Exception('Unable to split SELECT query, invalid syntax:\n' . $sql);
        }

		
		array_shift($parts);
		$parts = array_combine(array('select', 'columns', 'from', 'where', 'group by', 'having', 'order by', 'limit', 'options'), $parts + array_fill(0, 9, ''));

        if (isset($sets) && count($sets) > 1) {
    		$sets[0] =& $parts;
        	$parts = self::injectSubsets($sets);
        }
        
        return $parts;
	}

	/**
	 * Split insert/replace query.
	 *
	 * @param string $sql  SQL INSERT query statement
	 * @return array
	 */
	protected static function splitInsertQuery($sql)
	{
		$parts = null;
        if (!preg_match('/^\s*' .
		  '(INSERT|REPLACE)\b((?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\b)*)\s*' .
          '(?:\bINTO\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\((\s*' . self::REGEX_VALUES . ')\)\s*)?' .
		  '(?:\bSET\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bVALUES\s*(\(\s*' . self::REGEX_VALUES . '\)\s*(?:,\s*\(' . self::REGEX_VALUES . '\)\s*)*))?' .
		  '(\bSELECT\b\s*' . self::REGEX_VALUES . '|\#sub\d+\s*)?' .
		  '(?:\bON\s+DUPLICATE\s+KEY\s+UPDATE\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:;|$)/si', $sql, $parts))
		{
		 	throw new Exception("Unable to split INSERT/REPLACE query, invalid syntax:\n" . $sql);
		}
		
        $keys = array(strtolower($parts[1]), 'into', 'columns', 'set', 'values', 'query', 'on duplicate key update');
		return array_combine($keys, array_splice($parts, 2) + array_fill(0, 7, ''));
	}

	/**
	 * Split update query
	 *
	 * @param string $sql  SQL UPDATE query statement
	 * @return array
	 */
	protected static function splitUpdateQuery($sql)
	{
		if (preg_match('/\(\s*SELECT\b/i', $sql)) {
			$sets = self::extractSubsets($sql);
			$sql = $sets[0];
		}
		
		$parts = null;
		if (!preg_match('/^\s*' .
		  'UPDATE\b((?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\b)*)\s*' .
          '(' . self::REGEX_VALUES . ')?' .
		  '(?:\bSET\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bWHERE\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bLIMIT\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:;|$)/si', $sql, $parts))
        {
            throw new Exception("Unable to split UPDATE query, invalid syntax:\n" . $sql);
        }
          
		array_shift($parts);
		$parts = array_combine(array('update', 'table', 'set', 'where', 'limit'), $parts + array_fill(0, 5, ''));

        if (isset($sets) && count($sets) > 1) {
    		$sets[0] =& $parts;
        	$parts = self::injectSubsets($sets);
        }
        
        return $parts;
    }

	/**
	 * Split delete query.
	 *
	 * @param string $sql  SQL DELETE query statement
	 * @return array
	 */
	protected static function splitDeleteQuery($sql)
	{
		if (preg_match('/\(\s*SELECT\b/i', $sql)) {
			$sets = self::extractSubsets($sql);
			$sql = $sets[0];
		}
		
		$parts = null;
		if (!preg_match('/^\s*' .
		  'DELETE\b((?:\s+(?:LOW_PRIORITY|QUICK|IGNORE)\b)*)\s*' .
		  '(' . self::REGEX_VALUES . ')?' .
		  '(?:\bFROM\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bWHERE\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bORDER\s+BY\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:\bLIMIT\b\s*(' . self::REGEX_VALUES . '))?' .
		  '(?:;|$)/si', $sql, $parts)) 
        {
            throw new Exception("Unable to split DELETE query, invalid syntax:\n" . $sql);
        }
        
		array_shift($parts);
		$parts = array_combine(array('delete', 'columns', 'from', 'where', 'order by', 'limit'), $parts + array_fill(0 , 6, ''));
        
        if (isset($sets) && count($sets) > 1) {
    		$sets[0] =& $parts;
        	$parts = self::injectSubsets($sets);
        }
        
        return $parts;
	}
	
	/**
	 * Split delete query
	 *
	 * @param string $sql  SQL DELETE query statement
	 * @return array
	 */
	protected static function splitTruncateQuery($sql)
	{
		$parts = null;
		if (!preg_match('/^\s*' .
		  'TRUNCATE\b(\s+TABLE\b)?\s*' .
		  '(' . self::REGEX_VALUES . ')?' .
		  '(?:;|$)/si', $sql, $parts))
        {
            throw new Exception("Unable to split TRUNCATE query, invalid syntax: $sql");
        }
        
		array_shift($parts);
		return array_combine(array('truncate', 'table'), $parts);
	}
	
	/**
	 * Split set query
	 *
	 * @param string $sql  SQL SET query statement
	 * @return array
	 */
	protected static function splitSetQuery($sql)
	{
		$parts = null;
		if (!preg_match('/^\s*' .
		  'SET\b\s*' .
		  '(' . self::REGEX_VALUES . ')?' .
		  '(?:;|$)/si', $sql, $parts))
        {
            throw new Exception("Unable to split SET query, invalid syntax: $sql");
        }
        
		array_shift($parts);
		return array_combine(array('set'), $parts);
	}
	
	
	//------------- Split a part --------------------
	
	/**
	 * Return the columns of a (partual) query statement.
	 * 
	 * @param string $sql    SQL query or 'column, column, ...'
	 * @param int    $flags  DBQuery::SPLIT_% option
	 * @return array
	 */
	public static function splitColumns($sql, $flags=0)
	{
		if (is_array($sql) || self::getQueryType($sql)) {
			$parts = is_array($sql) ? $sql : self::split($sql);
			if (!isset($parts['columns'])) throw new Exception("It's not possible to extract columns of a " .  self::getQueryType($sql) . " query.");

            $sql = preg_replace('/^\(|\)$/', '', $parts['columns']);
		}
		
        if (!preg_match_all('/(?:`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|\((?:[^()]++|(?R))*\)|[^`"\'(),]++)++/', $sql, $match, PREG_PATTERN_ORDER)) {
            return array();
        }
        
        $columns =& $match[0];
        
        foreach ($columns as $key=>&$column) {
            $column = trim($column);
            if ($column === '') unset($columns[$key]);
        }
        
        return array_values($columns);
    }

	/**
	 * Return the columns of a (partual) query statement.
	 * 
	 * @param string $sql    SQL query or 'column, column, ...'
	 * @param int    $flags  DBQuery::SPLIT_% option
	 * @return array
	 */
	public static function splitSet($sql, $flags=0)
	{
		if (is_array($sql) || self::getQueryType($sql)) {
			$parts = self::split($sql);
			if (!isset($parts['set'])) throw new Exception("It's not possible to extract the set part of a $type query. $sql");

            $sql =& $parts['set'];
            unset($parts);
		}
		
        if (!preg_match_all('/\s*(?:((?:(?:`[^`]*+`|\w++)\.)*(?:`[^`]*+`|\w++)|@+\w++)\s*+=\s*+)?' .
          '(' . 
          '(?:(?:(?:`[^`]*+`|\w++)\.)*(?:`[^`]*+`|\w++)\.)?(?:`[^`]*+`|\w++)\s*+|' .
          '(?:`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\((?:[^()]++|(?R))*\)|\s++|\w++|[^`"\'\w\s(),])+' .
          ')(?=,|$|\))' .
          '/si', $sql, $matches, PREG_SET_ORDER))
        {
            return array();
        }
        
        $set = array();
        foreach ($matches as &$match) {
            $set[trim($match[1])] = trim($match[2]);
        }
        
        return $set;
    }
    
	/**
	 * Return the table names of a (partual) query statement.
     * 
	 * @param string $sql    SQL query or FROM part
	 * @return array  array(alias/name => table)
	 */
	public static function splitTables($sql)
	{
		if (is_array($sql) || self::getQueryType($sql)) {
	        $parts = self::split($sql);
	        if (array_key_exists('from', $parts)) $sql =& $parts['from'];
	          elseif (array_key_exists('table', $parts)) $sql =& $parts['table'];
	          elseif (array_key_exists('into', $parts)) $sql =& $parts['into'];
	          else throw new Exception("It's not possible to extract tables of a " . self::getQueryType($sql) . " query.");
		}
        
	    $matches = null;
		if (!preg_match_all('/(?:,\s*|(?:(?:NATURAL\s+)?(?:(?:LEFT|RIGHT)\s+)?(?:(?:INNER|CROSS|OUTER)\s+)?(?:STRAIGHT_)?JOIN\s*+))?+' .
		  '(?P<table>(?P<fullname>\((?:[^()]++|(?R))*\)\s*+|(?:(?P<db>`[^`]++`|\w++)\.)?(?P<name>`[^`]++`|\b\w++)\s*+)(?:(?P<alias>\bAS\s*+(?:`[^`]++`|\b\w++)|`[^`]++`|\b\w++(?<!\bON)(?<!\bNATURAL)(?<!\bLEFT)(?<!\bRIGHT)(?<!\bINNER)(?<!\bCROSS)(?<!\bOUTER)(?<!\bSTRAIGHT_JOIN)(?<!\bJOIN))\s*+)?)' .
		  '(?:ON\b\s*+(?P<on>(?:(?:`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*"|\'(?:[^\'\\\\]++|\\\\.)*\'|\s++|\w++(?<!\bNATURAL)(?<!\bLEFT)(?<!\bRIGHT)(?<!\bINNER)(?<!\bCROSS)(?<!\bOUTER)(?<!\bSTRAIGHT_JOIN)(?<!\bJOIN)|\((?:[^()]++|(?R))*\)|[^`"\'\w\s\,()]))+))?' .
		  '/si', $sql, $matches, PREG_SET_ORDER))
        {
            return array();
        }
        
        $tables = array();
        
		foreach ($matches as $i=>&$match) {
            if (preg_match('/^\s*\((.*)\)\s*$/', $match['fullname'], $m) && !preg_match('/^\s*\(\s*SELECT\b/i', $match['fullname'])) {
                $tables = array_merge($tables, self::splitTables($m[1]));
                continue;
            }
            
            $key = !empty($match['alias']) ? preg_replace('/^(?:AS\s*)?(`?)(.*?)\1\s*$/i', '$2', $match['alias']) : trim($match['name'], ' `');
            $tables[$key] = trim($match['fullname']);
		}
		
        return $tables;
    }

	/**
	 * Split limit in array(limit, offset)
	 *
	 * @param string $sql    SQL query or limit part
	 * @param int    $flags
	 * @return array
	 */
	public static function splitLimit($sql, $flags=0)
	{
		$type = self::getQueryType($sql);
		if (isset($type)) {
			$parts = self::split($sql);
            if (!isset($parts['limit'])) throw new Exception("A $type query doesn't have a LIMIT part.");
			$sql =& $parts['limit'];
		}
	
		$matches = null;
		if ($sql === null || $sql === '') return array(null, null);
		if (ctype_digit($sql)) return array($sql, null);
		if (preg_match('/^\s*(\d+)\s+OFFSET\s+(\d+)\s*$/', $sql, $matches)) return array($matches[1], $matches[2]);
		if (preg_match('/^\s*(\d+)\s*,\s*(\d+)\s*$/', $sql, $matches)) return array($matches[2], $matches[1]);
		
		throw new Exception("Invalid limit statement '$sql'");
	}

    
	//------------- Convert statement --------------------
    
	/**
	 * Build query to count the number of rows
	 * 
	 * @param mixed $sql    Statement
     * @param bool  $flags  Optional DBQuery::ALL_ROWS
     * @return string
	 */
	public static function buildCountQuery($sql, $flags=0)
	{
		$type = self::getQueryType($sql);
		
        $parts = is_array($sql) ? $sql : self::split($sql);
        if ($type == 'insert' && isset($parts['query'])) $parts = self::split($parts['query']);

        if (!isset($parts['from']) && !isset($parts['into']) && !isset($parts['table'])) throw new Exception("Unable to count rows for $type query. $sql");
        $table = isset($parts['from']) ? $parts['from'] : (isset($parts['into']) ? $parts['into'] : $parts['table']);
	
		if (($flags & DBQuery::ALL_ROWS) && isset($parts['limit'])) unset($parts['limit']);
   		
        if (!empty($parts['having'])) return "SELECT COUNT(*) FROM (" . (is_array($sql) ? self::join($sql) : $sql) . ") AS q";
        
        if ($type == 'SELECT') {
            $distinct = null;
            $column = preg_match('/\bDISTINCT\b/si', $parts['select']) ? "COUNT(DISTINCT " . trim($parts['columns']) . ")" : (!empty($parts['group by']) ? "COUNT(DISTINCT " . trim($parts['group by']) . ")" : "COUNT(*)");
        } else {
            $column = "COUNT(*)";
        }
        
        if (isset($parts['limit'])) {
            list($limit, $offset) = self::splitLimit($parts['limit']);
            if (isset($limit)) $column = "LEAST($column, $limit" . (isset($offset) ? ", $column - $offset" : '') . ")";
        }
   		
   		return self::join(array('select'=>'', 'columns'=>$column, 'from'=>$table, 'where'=>isset($parts['where']) ? $parts['where'] : ''));
	}
}
