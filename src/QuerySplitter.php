<?php

/*
 * BEWARE!!!
 * 
 * This class highly depends on complicated PCRE regular expressions. So if your not really really really good at
 * reading/writing these, don't touch this class. To prevent a regex getting in some crazy (or catastrophic)
 * backtracking loop, use regexbuddy (http://www.regexbuddy.com) or some other step-by-step regex debugger.
 * 
 * The performance of each function is really important, since these functions will be called a lot in 1 page and
 * should be considered abstraction overhead. The focus is on performance not readability of the code.
 * 
 * Expression REGEX_VALUE matches all quoted strings, all quoted identifiers and all words and all non-word chars
 * upto the next keyword. It uses atomic groups to look for the next keyword after each quoted string and complete word,
 * not after each char. Atomic groups are also necessary to prevent catastrophic backtracking when the regex should
 * fail.
 * 
 * Expressions like '/\w+\s*(abc)?\s*\w+z/' should be prevented. If this regex would try to match "ef    ghi", the regex
 * will first take all 3 spaces for the first \s*. When the regex fails it retries taking the first 2 spaces for the
 * first \s* and the 3rd space for the second \s*, etc, etc. This causes the matching to take more than 3 times as long
 * as '/\w+\s*(abc\s*)?\w+z/' would. This is the reason why trailing spaces are included with REGEX_VALUE and not
 * automatically trimmed.
 */

declare(strict_types=1);

namespace Persist\SQL\Query;

use Persist\SQL\Query\Dialect\Dialect;
use Persist\SQL\Query\Dialect as Dialects;

/**
 * Break down a mysql query statement to different parts, which can be altered and joined again.
 * Supported types: SELECT, INSERT, REPLACE, UPDATE, DELETE, TRUNCATE.
 *
 * SELECT ... UNION syntax is *not* supported.
 * DELETE ... USING syntax is *not* supported.
 *
 * Invalid query statements might give unexpected results.
 *
 * @todo It might be possible to use recursion instead of extracting subqueries, using \((SELECT\b)(?R)\). For query
 *   other that select, I should do (?:^\s++UPDATE ...|(?<!^)\s++SELECT ...) to match SELECT and not UPDATE statement
 *   in recursion.
 */
final class QuerySplitter
{
    protected Dialect $dialect;

    /** Any of the quote identifier options */
    protected const QUOTE_OPTIONS = 0xF00;

    /**
     * QuerySplitter constructor.
     *
     * @param Dialect|string $dialect
     */
    public function __construct($dialect)
    {
        $this->dialect = is_string($dialect) ? static::selectDialect($dialect) : $dialect;
    }

    /**
     * Get the dialect object from string.
     */
    public static function selectDialect(string $dialect): Dialect
    {
        switch (strtolower($dialect)) {
            case 'generic':
                return new Dialect();
            case 'mysql':
                return new Dialects\MySQL();
            default:
                throw new \DomainException("Unsupported SQL dialect '$dialect'");
        }
    }

    /**
     * Get the SQL dialect used by the query splitter.
     */
    public function getDialect(): string
    {
        return $this->dialect::NAME;
    }

    //------------- Basics -----------------------

    /**
     * Quote a value so it can be safely used in a query.
     *
     * @param mixed  $value
     * @param string $empty  Return $empty if $value is null
     * @return string
     */
    public function quoteValue($value, $empty = 'NULL')
    {
        if ($value === null) {
            return $empty;
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            foreach ($value as &$v) {
                $v = $this->quoteValue($v, $empty);
            }
            return '(' . join(', ', $value) . ')';
        }

        if ($value instanceof \DateTime) {
            if (date_default_timezone_get()) {
                $value->setTimezone(new \DateTimeZone(date_default_timezone_get())); // MySQL can't handle timezones
            }
            $value = $value->format('Y-m-d H:i:s');
        }

        return $this->dialect->quoteString($value);
    }

    /**
     * Unquote a string from the query to a value.
     *
     * @param string $quoted
     * @return mixed
     */
    public function unquoteValue(string $quoted)
    {
        $quoted = trim($quoted);

        switch (strtoupper($quoted)) {
            case 'NULL':
            case 'DEFAULT':
                return null;
            case 'TRUE':
                return true;
            case 'FALSE':
                return false;
        }

        if (is_numeric($quoted)) {
            return preg_match('/^[-+]?\d+$/', $quoted) ? (int)$quoted : (float)$quoted;
        }

        if (preg_match('/^' . $this->dialect::REGEX_QUOTED_STRING . '$/', $quoted)) {
            return $this->dialect->unquoteString($quoted);
        }

        throw new QueryBuildException("Unable to convert '$quoted' to a PHP value");
    }

    /**
     * Quotes a string so it can be used as an identifier like a table or column name.
     * Dots are seen as separator and are kept out of quotes.
     *
     * Doesn't quote expressions without Query::QUOTE_STRICT. This means it is not secure without this option.
     * @todo Cleanup misquoted TRIM function
     */
    public function quoteIdentifier(string $identifier, int $flags = 0): string
    {
        if ($flags & Query::QUOTE_STRICT) {
            return $this->quoteIdentifierStrict($identifier);
        }

        if (($flags & self::QUOTE_OPTIONS) === Query::QUOTE_NONE) {
            return $identifier;
        }

        // Check if all closing brackets have an opening parenthesis has an opening one to protect against SQL injection
        $matched = (bool)preg_match('/(?:(?:' . $this->dialect::REGEX_QUOTED . '|'
            . '[^()]++)*\((?:(?:' . $this->dialect::REGEX_QUOTED . '|'
            . '[^()]++)*|(?R))\))*(?:' . $this->dialect::REGEX_QUOTED . '|[^()]++)*/', $identifier, $match);
        if (!$matched || $match[0] != $identifier) {
            throw new QueryBuildException("Unable to quote '$identifier' safely");
        }

        if ($flags & Query::QUOTE_WORDS) {
            return $this->quoteIdentifierWords($identifier);
        }

        return $this->quoteIdentifierSmart($identifier);
    }

    /**
     * Quote identifier, not considering keywords and function calls.
     */
    protected function quoteIdentifierStrict(string $identifier): string
    {
        $identifier = trim($identifier);

        if (preg_match('/^\w++$/', $identifier)) {
            return $this->dialect::QUOTE_IDENTIFIER . $identifier . $this->dialect::QUOTE_IDENTIFIER ;
        }

        $quoted = preg_replace_callback(
            '/' . $this->dialect::REGEX_QUOTED_IDENTIFIER . '|([^' . $this->dialect::QUOTE_IDENTIFIER . '.]++)/',
            fn($match) => isset($match[1]) && $match[1] !== ''
                ? $this->dialect::QUOTE_IDENTIFIER . $match[1] . $this->dialect::QUOTE_IDENTIFIER
                : $match[0],
            $identifier
        );

        $isQuoted = is_string($quoted) && preg_match('/^(?:' . $this->dialect::REGEX_QUOTED_IDENTIFIER . '\.)*'
            . $this->dialect::REGEX_QUOTED_IDENTIFIER . '$/', $quoted);

        if (!$isQuoted) {
            throw new QueryBuildException("Unable to quote '$identifier' safely");
        }

        return $quoted;
    }

    /**
     * Quote identifier, considering keywords but not function calls.
     */
    protected function quoteIdentifierWords(string $identifier): string
    {
        return preg_replace_callback(
            '/' . $this->dialect::REGEX_QUOTED_STRING . '|(?<=^|[\s,])'
            . $this->dialect::REGEX_KEYWORDS . '(?=$|[\s,])|(?<=^|[\s,])COLLATE\s+\w++|(?<=^|[\s,])USING\s+\w++|'
            . $this->dialect::REGEX_QUOTED_IDENTIFIER . '|([^\s,.' . $this->dialect::REGEX_CHARS_QUOTES . '()]*'
            . $this->dialect::REGEX_UNQUOTED_IDENTIFIER . ')/i',
            fn($match) => isset($match[1]) && $match[1] !== ''
                ? $this->dialect::QUOTE_IDENTIFIER . $match[1] . $this->dialect::QUOTE_IDENTIFIER
                : $match[0],
            $identifier
        );
    }

    /**
     * Quote identifier, considerings keywords and function calls.
     */
    protected function quoteIdentifierSmart(string $identifier): string
    {
        $quoted = preg_replace_callback(
            '/' . $this->dialect::REGEX_QUOTED_STRING . '|\b'
            . $this->dialect::REGEX_KEYWORDS . '\b|\bCOLLATE\s+\w++|\bUSING\s+\w++|TRIM\s*\((?:BOTH|LEADING|TRAILING)|'
            . $this->dialect::REGEX_QUOTED_IDENTIFIER . '?|(\d*[a-z_]\w*\b)(?!\s*\()/i',
            fn($match) => isset($match[1]) && $match[1] !== ''
                ? $this->dialect::QUOTE_IDENTIFIER . $match[1] . $this->dialect::QUOTE_IDENTIFIER
                : $match[0],
            $identifier
        );

        return (bool)preg_match('/\bCAST\s*\(/i', $quoted)
            ? $this->quoteIdentifierCastCleanup($quoted)
            : $quoted;
    }

    /**
     * Unquote quoted types of CAST function.
     * The type is being quoted by `quoteIdentifierSmart`. This function reverts that.
     *
     *     CAST(`date_created` AS `DATETIME`)
     */
    protected function quoteIdentifierCastCleanup(string $expression, ?string $call = null): string
    {
        // Uses a recursive pattern to match nested expressions and call this function
        $expression = preg_replace_callback(
            '/((?:' . $this->dialect::REGEX_QUOTED . '|[^()' . $this->dialect::REGEX_CHARS_QUOTES . ']++)*)'
                . '(?:\(((?R)*)\))?/i',
            fn($match) => isset($match[2]) ? $this->quoteIdentifierCastCleanup($match[2], $match[1]) : $match[0],
            $expression
        );

        // Nested expression are the parameters of a CAST
        if ($call !== null && preg_match('/\bCAST\s*$/i', $call)) {
            $expression = preg_replace_callback(
                '/(\bAS\b\s*)(' . $this->dialect::REGEX_QUOTED_IDENTIFIER . ')(\s*)$/i',
                fn($match) => $match[1] . substr($match[2], 1, -1) . $match[3],
                $expression
            );
        }

        return $call !== null ? "{$call}({$expression})" : $expression;
    }

    /**
     * Check if expression is an identifier, like a field or table name.
     */
    public function isIdentifier(string $name): bool
    {
        return (bool)preg_match('/^\s*' . $this->dialect::REGEX_FULL_IDENTIFIER . '\s*$/', $name);
    }

    /**
     * Insert parameters into SQL query.
     * Don't mix unnamed ('?') and named (':key') placeholders.
     *
     * @param string                               $statement  Query string or Query::Statement object
     * @param array<int,mixed>|array<string,mixed> $params     Parameters to insert into statement on placeholders
     * @return string
     */
    public function bind(string $statement, array $params): string
    {
        if ($params === []) {
            return $statement;
        }

        $replace = function ($match) use (&$params) {
            if (isset($match['unnamed']) && $match['unnamed'] !== '' && $params !== []) {
                $value = array_shift($params);
            } elseif (isset($match['named']) && $match['named'] !== '' && array_key_exists($match['named'], $params)) {
                $value = $params[$match['named']];
            } else {
                return $match[0];
            }

            // This will cast value to a string, so make sure to check if there is a prefix and/or suffix.
            if (($match['prefix'] ?? '') !== '' || ($match['suffix'] ?? '') !== '') {
                $value = ($match['prefix'] ?? '') . $value . ($match['suffix'] ?? '');
            }

            return $this->quoteValue($value);
        };

        return preg_replace_callback(
            '/' . $this->dialect::REGEX_QUOTED . '|(?<prefix>%?)(?:(?<unnamed>\?)|:(?<named>\w++))(?<suffix>%?)/',
            $replace,
            $statement
        );
    }

    /**
     * Count the number of placeholders in a statement.
     *
     * @param string $statement
     * @return int
     */
    public function countPlaceholders(string $statement)
    {
        $regex = '/' . $this->dialect::REGEX_QUOTED . '|(\?|:\w++)/';

        return preg_match_all($regex, $statement, $matches, PREG_PATTERN_ORDER) !== 0
            ? count(array_filter($matches[1]))
            : 0;
    }


    //------------- Split / Build query -----------------------

    /**
     * Return the type of the query.
     *
     * @param string|array $sql  SQL query statement (or an array with parts)
     * @return string
     */
    public function getQueryType($sql): string
    {
        if (is_array($sql)) {
            $sql = array_key_first($sql);
        }

        return preg_match('/^\s*(' . $this->dialect::REGEX_CAPTURE_QUERY_TYPE . ')/si', $sql, $matches)
            ? strtoupper(preg_replace('/\s++/', ' ', $matches['type']))
            : ''; // Unknown query type
    }

    /**
     * Add parts to existing statement
     *
     * @param array<string,string>                   $parts
     * @param array<string,array<int,array<string>>> $add  [key=>[Query::PREPEND=>[], Query::APPEND=>array[]], ...]
     * @return array<string,string>
     */
    public function addParts(array $parts, array $add)
    {
        foreach ($add as $key => &$partsAdd) {
            $current = trim($parts[$key] ?? '');
            $prepend = $partsAdd[Query::PREPEND] ?? [];
            $append = $partsAdd[Query::APPEND] ?? [];

            switch ($key) {
                case 'columns':
                case 'set':
                case 'group by':
                case 'order by':
                    $parts[$key] = join(
                        ', ',
                        array_merge($prepend, $current !== '' ? [$current] : [], $append)
                    );
                    break;

                case 'values':
                    $parts[$key] =
                        ($prepend !== [] ? '(' . join('), (', $prepend) . ')' : '') .
                        ($prepend !== [] && $current !== '' ? ', ' : '') .
                        $current .
                        ($append !== [] && $current !== '' ? ', ' : '') .
                        ($append !== [] ? '(' . join('), (', $append) . ')' : '');
                    break;

                case 'from':
                case 'into':
                case 'table':
                    $useParentheses = $current !== '' &&
                        !preg_match('/^' . $this->dialect::REGEX_FULL_IDENTIFIER . '$/', $current);

                    $parts[$key] = trim(
                        ($prepend !== [] ? join(' ', $prepend) . ' ' : '') .
                            ($useParentheses ? "({$current})" : $current) .
                            ($append !== [] ? ' ' . join(' ', $append) : ''),
                        ', '
                    );
                    break;

                case 'where':
                case 'having':
                    $items = array_merge($prepend, $current !== '' ? [$current] : [], $append);
                    if ($items !== []) {
                        $parts[$key] = count($items) === 1
                            ? reset($items)
                            : '(' . join(') AND (', $items) . ')';
                    }
                    break;

                default:
                    $parts[$key] =
                        ($prepend !== [] ? join(' ', $prepend) . ' ' : '') .
                        $current .
                        ($append !== [] ? ' ' . join(' ', $append) : '');
            }
        }

        return $parts;
    }

    /**
     * Build a where expression.
     *
     * @param string|array<int,string>|array<string,mixed> $column  Expression, [column, ...], or [column=>value, ...]
     * @param mixed                                        $value   Value or array of values
     * @param int                                          $flags   Query::QUOTE_%
     * @return string
     */
    public function buildWhere($column, $value = null, $flags = 0): string
    {
        if (is_array($column)) {
            return $this->buildWhereForColumns($column, $flags);
        }

        $placeholders = $this->countPlaceholders($column);
        $column = $this->quoteIdentifier($column, $flags);

        // Simple case
        if ($placeholders === 0) {
            return !isset($value) || $value === []
                ? ($this->isIdentifier($column) ? '' : $column)
                : $column . (is_array($value) ? ' IN ' : ' = ') . $this->quoteValue($value);
        }

        // With placeholder
        return $this->bind($column, $placeholders === 1 ? [$value] : $value);
    }

    /**
     * Build where for each columns.
     *
     * @param array<int,string>|array<string,mixed> $columns
     * @param int                                   $flags
     * @return string
     */
    protected function buildWhereForColumns(array $columns, int $flags): string
    {
        foreach ($columns as $key => &$value) {
            $value = is_int($key)
                ? $this->buildWhere($value, null, $flags)
                : $this->buildWhere($key, $value, $flags);

            if ($value === null) {
                unset($columns[$key]);
            }
        }

        return $columns !== [] ? join(' AND ', $columns) : '';
    }


    //------------- Extract subsets --------------------

    /**
     * Extract subqueries from sql query and replace them with #subX in the main query.
     * Returns [main query, subquery1, subquery2, ...]
     * @todo Extract subsets should only go 1 level deep
     *
     * @param string $sql
     * @return array<string|array<mixed>>
     */
    public function extractSubsets(string $sql): array
    {
        $sets = [];
        $this->extractSubsetsRecursive($sql, $sets);

        return $sets;
    }

    /**
     * @see QuerySplitter::extractSubsets()
     *
     * @param  string                     $sql
     * @param  array<string|array<mixed>> $sets  Accumulator
     * @return int
     */
    public function extractSubsetsRecursive(string $sql, array &$sets): int
    {
        // Quick return: There are certainly no subqueries
        if (stripos($sql, 'SELECT', 6) === false) {
            return array_push($sets, $sql) - 1;
        }

        // Extract any subqueries
        $queryType = $this->getQueryType($sql);
        $offset = array_push($sets, null) - 1;

        if ($queryType === 'INSERT' || $queryType === 'REPLACE') {
            $parts = $this->split($sql);
            if (isset($parts['query'])) {
                $this->extractSubsetsRecursive($parts['query'], $sets);
                $parts['query'] = '#sub' . ($offset + 1);
                $sql = $this->join($parts);
            }
        }

        do {
            preg_match(
                '/(?:' . $this->dialect::REGEX_QUOTED . '|\((\s*SELECT\b.*\).*)|\w++|'
                    . '[^' . $this->dialect::REGEX_CHARS_QUOTES .'\w])*$/si',
                $sql,
                $matches,
                PREG_OFFSET_CAPTURE
            );

            if (!isset($matches[1])) {
                break;
            }

            $sql =
                substr($sql, 0, $matches[1][1]) .
                preg_replace_callback(
                    '/(?:' . $this->dialect::REGEX_QUOTED. '|'
                        . '([^' . $this->dialect::REGEX_CHARS_QUOTES . '()]+)|\((?R)\))*/si',
                    function ($match) use (&$sets) {
                        return '#sub' . $this->extractSubsetsRecursive($match[0], $sets);
                    },
                    substr($sql, $matches[1][1]),
                    1
                );
        } while (true);

        $sets[$offset] = $sql;

        return $offset;
    }

    /**
     * Inject extracted subsets back into main sql query.
     *
     * @param string|array<mixed> ...$sets  [main query, subquery, ...] or [main parts, subparts, ...]
     * @return string|array<mixed>
     */
    public function injectSubsets(...$sets)
    {
        if (count($sets) === 1) {
            return $sets[0];
        }

        $done = false;
        $target = &$sets[0];

        $fn = function ($match) use (&$sets, &$done) {
            if (isset($match[1]) && $match[1] !== '') {
                $done = false;
            }
            return empty($match[1])
                ? $match[0]
                : (is_array($sets[$match[1]]) ? $this->join($sets[$match[1]]) : $sets[$match[1]]);
        };

        while (!$done) {
            $done = true;
            $target = preg_replace_callback('/^' . $this->dialect::REGEX_QUOTED . '|(?:#sub(\d+))/', $fn, $target);
        }

        return $target;
    }


    //------------- Split query --------------------

    /**
     * Split a query.
     * If a part is not set within the SQL query, the part is an empty string.
     *
     * @param string $sql  SQL query statement
     * @return array<string>
     */
    public function split(string $sql): array
    {
        $type = $this->getQueryType($sql);

        switch ($type) {
            case 'SELECT':
                return $this->splitSelectQuery($sql);
            case 'INSERT':
            case 'REPLACE':
                return $this->splitInsertQuery($sql);
            case 'UPDATE':
                return $this->splitUpdateQuery($sql);
            case 'DELETE':
                return $this->splitDeleteQuery($sql);
            case 'TRUNCATE':
                return $this->splitTruncateQuery($sql);
            case 'SET':
                return $this->splitSetQuery($sql);
            case '':
                throw new UnsupportedQueryException("Unable to split query: $sql");
            default:
                throw new UnsupportedQueryException("Unable to split $type query.");
        }
    }

    /**
     * Join parts to create a query.
     * The parts are joined in the order in which they appear in the array.
     *
     * CAUTION: The parts are joined blindly (no validation), so shit in shit out
     *
     * @param array<string> $parts
     * @return string
     */
    public function join(array $parts): string
    {
        $type = $this->getQueryType($parts);
        $sqlParts = [];

        foreach ($parts as $key => &$part) {
            $part = is_array($part) ? join(", ", $part) : (string)$part;

            if ($part !== '' || $sqlParts === []) {
                if ($key === 'columns' && ($type === 'INSERT' || $type === 'REPLACE')) {
                    $part = '(' . $part . ')';
                }

                $sqlParts[] .= (
                    in_array($key, ['columns', 'query', 'table', 'options'], true)
                        ? ''
                        : strtoupper($key) . ($part !== '' ? " " : "")
                    ) . trim($part, " \t\n,");
            } else {
                unset($sqlParts[$key]);
            }
        }

        return join(' ', $sqlParts);
    }

    /**
     * Split select query.
     * NOTE: Splitting a query with a subquery is considerably slower.
     *
     * @param string $sql  SQL SELECT query statement
     * @return array<string>
     */
    protected function splitSelectQuery(string $sql): array
    {
        if (preg_match('/\(\s*SELECT\b/i', $sql)) {
            $sets = $this->extractSubsets($sql);
            $sql = $sets[0];
        }

        $parts = null;
        if (!preg_match('/^\s*' .
            'SELECT\b((?:\s+' . $this->dialect::REGEX_SELECT_MODS . '\b)*)\s*(' . $this->dialect::REGEX_VALUE . ')' .
            '(?:' .
            '(?:\bFROM\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bWHERE\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bGROUP\s+BY\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bHAVING\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bORDER\s+BY\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bLIMIT\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(\b' . $this->dialect::REGEX_SELECT_OPTIONS . '\b.*?)?' .
            ')?' .
            '(?:;|$)/si', $sql, $parts)
        ) {
            throw new QueryBuildException("Unable to split SELECT query, invalid syntax: $sql");
        }


        array_shift($parts);
        $parts = array_combine(
            ['select', 'columns', 'from', 'where', 'group by', 'having', 'order by', 'limit', 'options'],
            $parts + array_fill(0, 9, '')
        );

        if (isset($sets) && count($sets) > 1) {
            $sets[0] = $parts;
            $parts = $this->injectSubsets(...$sets);
        }

        return $parts;
    }

    /**
     * Split insert/replace query.
     *
     * @param string $sql  SQL INSERT query statement
     * @return array<string>
     */
    protected function splitInsertQuery(string $sql): array
    {
        $parts = null;
        if (!preg_match('/^\s*' .
            '(INSERT|REPLACE)\b((?:\s+' . $this->dialect::REGEX_INSERT_MODS . '\b)*)\s*' .
            '(?:\bINTO\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\((\s*' . $this->dialect::REGEX_VALUE . ')\)\s*)?' .
            '(?:\bSET\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bVALUES\s*(\(\s*' . $this->dialect::REGEX_VALUE . '\)\s*' .
            '(?:,\s*\(' . $this->dialect::REGEX_VALUE . '\)\s*)*))?' .
            '(\bSELECT\b\s*' . $this->dialect::REGEX_VALUE . '|#sub\d+\s*)?' .
            '(?:\bON\s+DUPLICATE\s+KEY\s+UPDATE\b\s*(' . $this->dialect::REGEX_VALUE . '))?' . // TODO MySQL specific
            '(?:;|$)/si', $sql, $parts)
        ) {
            throw new QueryBuildException("Unable to split INSERT/REPLACE query, invalid syntax: $sql");
        }

        return array_combine(
            [strtolower($parts[1]), 'into', 'columns', 'set', 'values', 'query', 'on duplicate key update'],
            array_splice($parts, 2) + array_fill(0, 7, '')
        );
    }

    /**
     * Split update query
     *
     * @param string $sql  SQL UPDATE query statement
     * @return array<string>
     */
    protected function splitUpdateQuery(string $sql): array
    {
        if (preg_match('/\(\s*SELECT\b/i', $sql)) {
            $sets = $this->extractSubsets($sql);
            $sql = $sets[0];
        }

        $parts = null;
        if (!preg_match('/^\s*' .
            'UPDATE\b((?:\s+' . $this->dialect::REGEX_UPDATE_MODS . '\b)*)\s*' .
            '(' . $this->dialect::REGEX_VALUE . ')?' .
            '(?:\bSET\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bWHERE\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bLIMIT\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:;|$)/si', $sql, $parts)
        ) {
            throw new QueryBuildException("Unable to split UPDATE query, invalid syntax: $sql");
        }

        array_shift($parts);
        $parts = array_combine(
            ['update', 'table', 'set', 'where', 'limit'],
            $parts + array_fill(0, 5, '')
        );

        if (isset($sets) && count($sets) > 1) {
            $sets[0] = $parts;
            $parts = $this->injectSubsets(...$sets);
        }

        return $parts;
    }

    /**
     * Split delete query.
     *
     * @param string $sql  SQL DELETE query statement
     * @return array
     */
    protected function splitDeleteQuery($sql)
    {
        if (preg_match('/\(\s*SELECT\b/i', $sql)) {
            $sets = $this->extractSubsets($sql);
            $sql = $sets[0];
        }

        $parts = null;
        if (!preg_match('/^\s*' .
            'DELETE\b((?:\s+' . $this->dialect::REGEX_DELETE_MODS . '\b)*)\s*' .
            '(' . $this->dialect::REGEX_VALUE . ')?' .
            '(?:\bFROM\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bWHERE\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bORDER\s+BY\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:\bLIMIT\b\s*(' . $this->dialect::REGEX_VALUE . '))?' .
            '(?:;|$)/si', $sql, $parts)
        ) {
            throw new QueryBuildException("Unable to split DELETE query, invalid syntax: $sql");
        }

        array_shift($parts);
        $parts = array_combine(
            ['delete', 'columns', 'from', 'where', 'order by', 'limit'],
            $parts + array_fill(0, 6, '')
        );

        if (isset($sets) && count($sets) > 1) {
            $sets[0] = $parts;
            $parts = $this->injectSubsets(...$sets);
        }

        return $parts;
    }

    /**
     * Split delete query
     *
     * @param string $sql  SQL DELETE query statement
     * @return array
     */
    protected function splitTruncateQuery($sql)
    {
        $parts = null;
        if (!preg_match('/^\s*' .
            'TRUNCATE\b(\s+TABLE\b)?\s*' .
            '(' . $this->dialect::REGEX_VALUE . ')?' .
            '(?:;|$)/si', $sql, $parts)
        ) {
            throw new QueryBuildException("Unable to split TRUNCATE query, invalid syntax: $sql");
        }

        array_shift($parts);

        return array_combine(['truncate', 'table'], $parts);
    }

    /**
     * Split set query
     *
     * @param string $sql  SQL SET query statement
     * @return array
     */
    protected function splitSetQuery($sql)
    {
        $parts = null;
        if (!preg_match('/^\s*' .
            'SET\b\s*' .
            '(' . $this->dialect::REGEX_VALUE . ')?' .
            '(?:;|$)/si', $sql, $parts)
        ) {
            throw new QueryBuildException("Unable to split SET query, invalid syntax: $sql");
        }

        array_shift($parts);

        return array_combine(['set'], $parts);
    }


    //------------- Split a part --------------------

    /**
     * Return the columns of a (partial) query statement.
     *
     * @param string|array $sql  SQL query or parts
     * @return array
     */
    public function splitColumns($sql)
    {
        $parts = is_array($sql) ? $sql : $this->split($sql);

        if (!isset($parts['columns'])) {
            $type = $this->getQueryType($sql);
            throw new QueryBuildException("It's not possible to extract columns of a $type query.");
        }

        $statement = $parts['columns'];

        if (!preg_match_all(
            '/(?:' . $this->dialect::REGEX_QUOTED . '|\((?:[^()]++|(?R))*\)|'
                . '[^' . $this->dialect::REGEX_CHARS_QUOTES . '(),]++)++/',
            $statement,
            $match,
            PREG_PATTERN_ORDER
        )) {
            return [];
        }

        $columns = $match[0];
        unset($match);

        foreach ($columns as $key => &$column) {
            $column = trim($column);
            if ($column === '') {
                unset($columns[$key]);
            }
        }

        return array_values($columns);
    }

    /**
     * Return the columns of a (partial) query statement.
     *
     * @param string|array $sql    SQL query or parts
     * @param int          $flags  Optional Query::UNQUOTE
     * @return array<string>
     */
    public function splitSet($sql, int $flags = 0): array
    {
        $parts = is_array($sql) ? $sql : $this->split($sql);

        if (!isset($parts['set'])) {
            $type = $this->getQueryType($sql);
            throw new QueryBuildException("It's not possible to extract the set part of a $type query.");
        }

        $statement = $parts['set'];

        if (!preg_match_all('/\s*(?:(' . $this->dialect::REGEX_FULL_IDENTIFIER . '|@+\w++)\s*+=\s*+)?' .
            '(' . $this->dialect::REGEX_FULL_IDENTIFIER . '\s*+|' .
            '(?:' . $this->dialect::REGEX_QUOTED . '|\((?:[^()]++|(?R))*\)|\s++|\w++|[^' .
            $this->dialect::REGEX_CHARS_QUOTES . '\w\s(),])+)(?=,|$|\))' .
            '/si', $statement, $matches, PREG_SET_ORDER)
        ) {
            return [];
        }

        $set = [];
        foreach ($matches as &$match) {
            $field = trim(trim($match[1]), $this->dialect::QUOTE_IDENTIFIER);
            $set[$field] = ($flags & Query::UNQUOTE) === 0
                ? trim($match[2])
                : $this->unquoteValue(trim($match[2]));
        }

        return $set;
    }

    /**
     * Return the table names of a (partial) query statement.
     *
     * @param array|string $sql    SQL query or FROM part
     * @return array               array(alias/name => table)
     */
    public function splitTables($sql)
    {
        $parts = is_array($sql) ? $sql : $this->split($sql);
        $statement = $parts['table'] ?? $parts['from'] ?? $parts['into'] ?? null;

        if ($statement === null) {
            $type = $this->getQueryType($sql);
            throw new QueryBuildException("It's not possible to extract tables of a $type query.");
        }

        if (!preg_match_all('/(?:,\s*|' . $this->dialect::REGEX_JOIN . '\s*+)?+' .
            '(?P<table>(?P<fullname>\((?:[^()]++|(?R))*\)\s*+|' .
            '(?:(?P<db>' . $this->dialect::REGEX_IDENTIFIER . ')\.)?' .
            '(?P<name>`[^`]++`|\b\w++)\s*+)' .
            '(?:(?P<alias>\bAS\s*+(?:' . $this->dialect::REGEX_QUOTED_IDENTIFIER . '|\b\w++)|' .
            $this->dialect::REGEX_QUOTED_IDENTIFIER . '|\b\w++(?<!\bON)' .
            $this->dialect::REGEX_LOOKBEHIND_JOIN . ')\s*+)?)' .
            '(?:ON\b\s*+(?P<on>(?:' . $this->dialect::REGEX_QUOTED . '|\s++|\w++' .
            $this->dialect::REGEX_LOOKBEHIND_JOIN .
            '|\((?:[^()]++|(?R))*\)|[^' . $this->dialect::REGEX_CHARS_QUOTES . '\w\s,()])+))?' .
            '/si', $statement, $matches, PREG_SET_ORDER)
        ) {
            return [];
        }

        $tables = [];

        foreach ($matches as $i => &$match) {
            $nested = preg_match('/^\s*\((.*)\)\s*$/', $match['fullname'], $m) &&
                !preg_match('/^\s*\(\s*SELECT\b/i', $match['fullname']);

            if ($nested) {
                $tables = array_merge($tables, $this->splitTables(['table' => $m[1]]));
                continue;
            }

            $key = ($match['alias'] ?? '') !== ''
                ? preg_replace('/^(?:AS\s*)?(`?)(.*?)\1\s*$/i', '$2', $match['alias'])
                : trim($match['name'], ' `');
            $tables[$key] = trim($match['fullname']);
        }

        return $tables;
    }

    /**
     * Return the columns of a (partial) query statement.
     *
     * @param string|array $sql    SQL query or parts
     * @param int          $flags  Optional Query::UNQUOTE
     * @return array
     */
    public function splitValues($sql, int $flags = 0)
    {
        $parts = is_array($sql) ? $sql : $this->split($sql);

        if (!isset($parts['values'])) {
            $type = $this->getQueryType($sql);
            throw new QueryBuildException("It's not possible to extract values of a $type query.");
        }

        $statement = $parts['values'];

        $regex = '/(?:' . $this->dialect::REGEX_QUOTED . '|\((?:[^()]++|(?R))*\)|'
            . '[^' . $this->dialect::REGEX_CHARS_QUOTES . '(),]++)++/';

        if (!preg_match_all($regex, $statement, $match, PREG_PATTERN_ORDER)) {
            return [];
        }

        $sets = $match[0];
        $values = [];

        foreach ($sets as $set) {
            $expressions = preg_replace('/^\s*\(|\)\s*$/', '', $set);

            if (!preg_match_all($regex, $expressions, $match, PREG_PATTERN_ORDER)) {
                continue;
            }

            $values[] = $match[0];
        }

        if (($flags & Query::UNQUOTE) === 0) {
            foreach ($values as &$row) {
                foreach ($row as &$value) {
                    $value = trim($value);
                }
            }
        } else {
            foreach ($values as &$row) {
                foreach ($row as &$value) {
                    $value = $this->unquoteValue($value);
                }
            }
        }

        return $values;
    }

    /**
     * Split limit in array(limit, offset)
     *
     * @param string|array $sql  SQL query, limit part or parts
     * @return array{int|null,int|null}
     */
    public function splitLimit($sql): array
    {
        $parts = is_array($sql) ? $sql : $this->split($sql);

        if (!isset($parts['limit'])) {
            $type = $this->getQueryType($sql);
            throw new QueryBuildException("A $type query doesn't have a LIMIT part.");
        }

        $statement = trim($parts['limit']);

        if ($statement === '') {
            return [null, null];
        }

        if (preg_match('/^\s*(\d+)\s+OFFSET\s+(\d+)\s*$/', $statement, $matches)) {
            return [(int)$matches[1], (int)$matches[2]];
        }

        if (preg_match('/^\s*(\d+)\s*(?:,\s*(\d+)\s*)?$/', $statement, $matches)) {
            return isset($matches[2]) && $matches[2] !== ''
                ? [(int)$matches[2], (int)$matches[1]]
                : [(int)$matches[1], null];
        }

        throw new QueryBuildException("Invalid limit statement '$statement'");
    }


    //------------- Convert statement --------------------

    /**
     * Build query to count the number of rows.
     *
     * @param string|array $sql    SQL Statement or parts
     * @param int          $flags  Optional Query::ALL_ROWS
     * @return string
     */
    public function buildCountQuery($sql, int $flags = 0): string
    {
        $type = $this->getQueryType($sql);

        $parts = is_array($sql) ? $sql : $this->split($sql);
        if (($type === 'INSERT' || $type === 'REPLACE') && isset($parts['query'])) {
            $parts = $this->split($parts['query']);
        }

        $table = $parts['from'] ?? $parts['into'] ?? $parts['table'] ?? null;

        if ($table === null) {
            throw new QueryBuildException("Unable to count rows for $type query. $sql");
        }

        if (($flags & Query::ALL_ROWS) && isset($parts['limit'])) {
            unset($parts['limit']);
        }

        if ($type === 'SELECT' && ($parts['having'] ?? '') !== '') {
            return "SELECT COUNT(*) FROM (" . $this->join($parts) . ") AS q";
        }

        if ($type === 'SELECT') {
            $distinct = null;
            $column = preg_match('/\bDISTINCT\b/si', $parts['select'])
                ? "COUNT(DISTINCT " . trim($parts['columns']) . ")"
                : (($parts['group by'] ?? '') !== '' ? "COUNT(DISTINCT " . trim($parts['group by']) . ")" : "COUNT(*)");
        } else {
            $column = "COUNT(*)";
        }

        if (isset($parts['limit'])) {
            [$limit, $offset] = $this->splitLimit($parts);
            if (isset($limit)) {
                $column = "LEAST(" . (isset($offset) ? "$column - $offset" : $column) . ", $limit)";
            }
        }

        return $this->join([
            'select' => '',
            'columns' => $column,
            'from' => $table,
            'where' => $parts['where'] ?? ''
        ]);
    }
}
