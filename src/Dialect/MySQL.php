<?php

declare(strict_types=1);

namespace Persist\SQL\Query\Dialect;

/**
 * Constants for the MySQL dialect of SQL.
 */
class MySQL extends Dialect
{
    public const NAME = 'MySQL';

    public const REGEX_VALUE = '(?:\w++|`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|\s++|'
        . '[^`"\'\w\s])*?';
    public const REGEX_QUOTED = '(?:`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\')';
    public const REGEX_QUOTED_STRING = '(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\)';
    public const REGEX_QUOTED_IDENTIFIER = '(?:`[^`]*+`)';
    public const REGEX_IDENTIFIER = '(?:`[^`]*+`|\d*[a-z_]\w*+)';
    public const REGEX_FULL_IDENTIFIER = '(?:(?:\d*[a-z_]\w*+|`[^`]*+`)(?:\.(?:\d*[a-z_]\w*+|`[^`]*+`)){0,2})';
    public const REGEX_UNQUOTED_IDENTIFIER = '(?:\d*[a-z_][^\s,.`\'"()]*)';
    public const REGEX_CHARS_QUOTES = '\'"`';

    public const REGEX_CAPTURE_QUERY_TYPE = '(?:(?<type>SELECT|INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|CALL|DO|HANDLER'
        . '|LOAD\s+(?:DATA|XML)\s+INFILE|(?:ALTER|CREATE|DROP|RENAME)\s+(?:DATABASE|TABLE|VIEW|FUNCTION|PROCEDURE|'
        . 'TRIGGER|INDEX)|PREPARE|EXECUTE|DEALLOCATE\s+PREPARE|DESCRIBE|EXPLAIN|HELP|USE|LOCK\s+TABLES|UNLOCK\s+TABLES|'
        . 'SET|SHOW|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE SAVEPOINT|CACHE\s+INDEX|FLUSH|KILL|'
        . 'LOAD|RESET|PURGE\s+BINARY\s+LOGS|START\s+SLAVE|STOP\s+SLAVE)\b)';
    public const REGEX_KEYWORDS = '(?:NULL|TRUE|FALSE|DEFAULT|DIV|AND|OR|XOR|NOT|IN|IS|BETWEEN|R?LIKE|REGEXP|'
        . 'SOUNDS\s+LIKE|MATCH|AS|CASE|WHEN|THEN|END|ASC|DESC|BINARY)';
    public const REGEX_SELECT_MODS = '(?:ALL|DISTINCT|DISTINCTROW|HIGH_PRIORITY|STRAIGHT_JOIN|SQL_SMALL_RESULT|'
        . 'SQL_BIG_RESULT|SQL_BUFFER_RESULT|SQL_CACHE|SQL_NO_CACHE|SQL_CALC_FOUND_ROWS)';
    public const REGEX_SELECT_OPTIONS = '(?:PROCEDURE|INTO|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s*MODE|CASCADE\s*ON)';
    public const REGEX_INSERT_MODS = '(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)';
    public const REGEX_UPDATE_MODS = '(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)';
    public const REGEX_DELETE_MODS = '(?:LOW_PRIORITY|QUICK|IGNORE)';
    public const REGEX_JOIN = '(?:(?:NATURAL\s+)?(?:(?:LEFT|RIGHT)\s+)?(?:(?:INNER|CROSS|OUTER)\s+)?'
        . '(?:STRAIGHT_)?JOIN)';
    public const REGEX_LOOKBEHIND_JOIN = '(?<!\bNATURAL)(?<!\bLEFT)(?<!\bRIGHT)(?<!\bINNER)(?<!\bCROSS)(?<!\bOUTER)'
        . '(?<!\bSTRAIGHT_JOIN)(?<!\bJOIN)';

    public const QUOTE_STRING = '\'';
    public const QUOTE_IDENTIFIER = '`';

    /**
     * Quote a string to be used in an SQL query.
     */
    public function quoteString(string $value): string
    {
        return
            static::QUOTE_STRING .
            addcslashes($value, "\0\r\n\f\t" . static::QUOTE_STRING) .
            static::QUOTE_STRING;
    }
}
