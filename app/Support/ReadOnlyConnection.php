<?php

namespace App\Support;

use Illuminate\Database\MySqlConnection;
use RuntimeException;

/**
 * A connection to a shop's database that cannot write to it.
 *
 * PANEL_DOC Section 1, rule 3: a shop's own data is read, never rewritten. The
 * panel manages installs, not the trade inside them, and Section 7 says in as
 * many words that it may never write to a shop's business tables.
 *
 * Intent is not enough for that. The panel connects with the shop's own
 * credentials — read out of its .env, because that is the only account that
 * exists — and those credentials can do anything. Nothing at the database end
 * says no. So the refusal is here, in the one object every query has to pass
 * through, rather than in the discipline of whoever writes the next query.
 *
 * What survives is `select` and `selectOne`. Everything that changes a row, a
 * table or a session is refused by name, loudly, before it reaches the server:
 * a bug in the panel becomes a failed health check rather than a shopkeeper's
 * missing sale.
 *
 * This is not a security boundary against a hostile panel — anybody who can
 * edit this file can delete this class. It is a guard against the ordinary
 * mistake: a `->update()` written on a model that happens to be bound to a
 * shop's connection, at four in the afternoon, by someone who meant the
 * panel's own.
 */
class ReadOnlyConnection extends MySqlConnection
{
    /*
     * Signatures match the parent exactly, including MySqlConnection's third
     * argument to insert() and the parent's undeclared return types. A widened
     * or narrowed one is a fatal error at class-load time — which is how the
     * first version of this announced itself.
     */

    public function statement($query, $bindings = [])
    {
        throw $this->refuse('statement', $query);
    }

    public function affectingStatement($query, $bindings = [])
    {
        throw $this->refuse('affectingStatement', $query);
    }

    public function insert($query, $bindings = [], $sequence = null)
    {
        throw $this->refuse('insert', $query);
    }

    public function update($query, $bindings = [])
    {
        throw $this->refuse('update', $query);
    }

    public function delete($query, $bindings = [])
    {
        throw $this->refuse('delete', $query);
    }

    public function unprepared($query)
    {
        throw $this->refuse('unprepared', $query);
    }

    /**
     * Transactions too.
     *
     * Nothing read-only needs one, and a transaction opened on a shop's
     * connection is a lock held on a live till. Refusing it here also closes
     * the obvious way round the methods above.
     */
    public function beginTransaction()
    {
        throw $this->refuse('beginTransaction', '');
    }

    private function refuse(string $method, string $query): RuntimeException
    {
        return new RuntimeException(sprintf(
            "The panel tried to %s on a shop's database [%s]. It may only read. ".
            "PANEL_DOC Section 1 rule 3: a shop's own data is read, never rewritten.%s",
            $method,
            $this->getDatabaseName(),
            $query === '' ? '' : PHP_EOL.'The statement was: '.$query,
        ));
    }
}
