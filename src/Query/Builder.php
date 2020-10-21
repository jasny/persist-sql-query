<?php

declare(strict_types=1);

namespace Jasny\Persist\SQL\Query;

use Jasny\Persist\SQL\Query\Dialect\Generic as Dialect;
use Jasny\Persist\SQL\Query;

/**
 * Factory methods for Query objects.
 */
class Builder
{
    protected Dialect $dialect;

    /**
     * QueryBuilder constructor.
     *
     * @param Dialect|string $dialect
     */
    public function __construct($dialect = 'generic')
    {
        $this->dialect = $dialect instanceof Dialect ? $dialect : QuerySplitter::selectDialect($dialect);
    }

    /**
     * Create a SELECT query.
     */
    public function select(string ...$columns): Query
    {
        $query = new Query('SELECT', $this->dialect);

        if ($columns !== []) {
            $query->columns($columns);
        }

        return $query;
    }

    /**
     * Create a SELECT query with COUNT(*).
     */
    public function count(): Query
    {
        return new Query("SELECT COUNT(*)", $this->dialect);
    }

    /**
     * Create an INSERT INTO query.
     */
    public function insert(): Query
    {
        return new Query("INSERT INTO", $this->dialect);
    }

    /**
     * Create a REPLACE INTO query.
     * This is only available for MySQL dialect of SQL.
     */
    public function replace(): Query
    {
        if (strtolower($this->dialect::NAME) !== 'mysql') {
            throw new \BadMethodCallException("REPLACE query is only available for MySQL");
        }

        return new Query("REPLACE INTO", $this->dialect);
    }

    /**
     * Create an INSERT INTO query with ON CONFLICT / ON DUPLICATE KEY.
     */
    public function upsert(): Query
    {
        return (new Query("INSERT INTO", $this->dialect))
            ->onConflictUpdate();
    }

    /**
     * Create an UPDATE query.
     */
    public function update(?string $table = null): Query
    {
        $query = new Query("UPDATE", $this->dialect);

        if ($table !== null) {
            $query->table($table);
        }

        return $query;
    }

    /**
     * Create a DELETE query.
     */
    public function delete(): Query
    {
        return new Query('DELETE', $this->dialect);
    }


    /**
     * Create a query to begin a transaction.
     */
    public function begin(): Query
    {
        return new Query('BEGIN', $this->dialect);
    }

    /**
     * Alias of `begin()`
     */
    final public function startTransaction(): Query
    {
        return $this->begin();
    }

    /**
     * Create a query to set a transaction savepoint.
     */
    public function savepoint(string $identifier): Query
    {
        if (preg_match('/\W/', $identifier)) {
            throw new QueryBuildException("Savepoint identifier must be alphanumeric");
        }

        return (new Query("SAVEPOINT $identifier", $this->dialect));
    }

    /**
     * Create a query to rollback a transaction.
     *
     * @param string|null $identifier  Savepoint identifier
     * @return Query
     */
    public function rollback(?string $identifier = null): Query
    {
        if ($identifier !== null && preg_match('/\W/', $identifier)) {
            throw new QueryBuildException("Savepoint identifier must be alphanumeric");
        }

        return (new Query('ROLLBACK' . ($identifier !== null ? " TO $identifier" : ''), $this->dialect));
    }

    /**
     * Create a query to commit a transaction
     */
    public function commit(): Query
    {
        return new Query('COMMIT', $this->dialect);
    }
}
