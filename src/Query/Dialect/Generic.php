<?php

declare(strict_types=1);

namespace Jasny\Persist\SQL\Query\Dialect;

/**
 * Base class for dialects.
 * Defines constants for the ANSI standard SQL.
 * @internal
 */
class Generic
{
    public const NAME = 'ANSI';

    public const REGEX_VALUE = '(?:\w++|"[^"]*+"|\'(?:[^\']++|\'\')*+\'|\s++|[^"\'\w\s])*?';
    public const REGEX_QUOTED = '(?:"[^"]*+"|\'(?:[^\'\\\\]++|\\\\.)*+\')';
    public const REGEX_QUOTED_STRING = '(?:\'(?:[^\']++|\'\')*+\')';
    public const REGEX_QUOTED_IDENTIFIER = '(?:"[^"]*+")';
    public const REGEX_UNQUOTED_IDENTIFIER = '(?:\b[a-z_][^\s,.\'"()]*)';
    public const REGEX_IDENTIFIER = '(?:"[^"]*+"|\b[a-z_]\w*+)';
    public const REGEX_FULL_IDENTIFIER = '(?:(?:"[^"]*+"|\b[a-z_]\w*+)(?:\.(?:["[^"]*+"|\b[a-z_]\w*+)){0,2})';
    public const REGEX_CHARS_QUOTES = '\'"';

    // TODO Copied from MySQL. Check which are in the ANSI standard.
    public const REGEX_CAPTURE_QUERY_TYPE = '(?:(?<type>SELECT|INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|CALL|DO|HANDLER|'
        . '(?:ALTER|CREATE|DROP|RENAME)\s+(?:DATABASE|TABLE|VIEW|FUNCTION|PROCEDURE|TRIGGER|INDEX)|PREPARE|EXECUTE|'
        . 'DEALLOCATE\s+PREPARE|DESCRIBE|EXPLAIN|HELP|USE|LOCK\s+TABLES|UNLOCK\s+TABLES|SET|SHOW|START\s+TRANSACTION|'
        . 'BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE\s+SAVEPOINT|CACHE\s+INDEX|FLUSH|KILL|LOAD|RESET|'
        . 'PURGE\s+BINARY\s+LOGS|START\s+SLAVE|STOP\s+SLAVE)\b)';
    public const REGEX_KEYWORDS = '(?:NULL|TRUE|FALSE|DEFAULT|DIV|AND|OR|XOR|NOT|IN|IS|BETWEEN|LIKE|MATCH|AS|CASE|WHEN|'
        . 'THEN|END|ASC|DESC|BINARY)';
    public const REGEX_SELECT_MODS = '(?:ALL|DISTINCT|DISTINCTROW)';
    public const REGEX_SELECT_OPTIONS = '(?:PROCEDURE|INTO|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s*MODE|CASCADE\s*ON)';
    public const REGEX_INSERT_MODS = '';
    public const REGEX_UPDATE_MODS = '';
    public const REGEX_DELETE_MODS = '';
    public const REGEX_JOIN = '(?:(?:NATURAL\s+)?(?:(?:LEFT|RIGHT)\s+)?(?:(?:INNER|CROSS|OUTER)\s+)?JOIN\s*+)';
    public const REGEX_LOOKBEHIND_JOIN = '(?<!\bNATURAL)(?<!\bLEFT)(?<!\bRIGHT)(?<!\bINNER)(?<!\bCROSS)(?<!\bOUTER)'
        . '(?<!\bJOIN)';

    public const QUOTE_STRING = '\'';
    public const QUOTE_IDENTIFIER = '`';


    /**
     * Quote a string to be used in an SQL query.
     */
    public function quoteString(string $value): string
    {
        return
            static::QUOTE_STRING .
            str_replace(static::QUOTE_STRING, static::QUOTE_STRING . static::QUOTE_STRING, $value) .
            static::QUOTE_STRING;
    }

    /**
     * Unquote a string that is taken from an SQL query.
     */
    public function unquoteString(string $quoted): string
    {
        if (!preg_match('/^' . static::QUOTE_STRING . '(.*?)' . static::QUOTE_STRING . '$/', $quoted, $match)) {
            return ''; // Expecting this function to be called with a correctly quoted string.
        }

        return str_replace(static::QUOTE_STRING . static::QUOTE_STRING, static::QUOTE_STRING, $match[1]);
    }
}
