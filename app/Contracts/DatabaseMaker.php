<?php

namespace App\Contracts;

/**
 * Making a new shop somewhere to keep its data — PANEL_DOC Section 4.
 *
 * Behind an interface because the two hosts the panel has to work on cannot do
 * this the same way. Section 4 measured it: on this cPanel account plain
 * `CREATE DATABASE` is **denied**, and the way through is UAPI. On a plain
 * MySQL server — Soran's own machine, and anything that is not cPanel — UAPI
 * does not exist and SQL is all there is.
 *
 * Whichever it is, the shape is the same: a database, a user, and that user
 * given rights on that database.
 */
interface DatabaseMaker
{
    /**
     * The full name this host will really give a database called `$wanted`.
     *
     * cPanel prefixes everything with the account name, so a database asked for
     * as `bazaar_shop` is created as `soransto_bazaar_shop`. The customer row
     * has to record the name the shop will actually connect to, not the one
     * that was typed — a panel that stores the wrong one cannot read that shop
     * again.
     */
    public function realName(string $wanted): string;

    /**
     * @throws \RuntimeException with what the host said, if anything fails.
     */
    public function create(string $database, string $user, string $password): void;

    /**
     * Undo it. Used only to roll back a creation that failed part-way.
     *
     * Never throws: it runs when something has already gone wrong, and a
     * rollback that explodes buries the error that caused it. What it could not
     * clean up is returned instead, so the screen can say what is left behind.
     *
     * @return list<string> what could not be removed
     */
    public function drop(string $database, string $user): array;
}
