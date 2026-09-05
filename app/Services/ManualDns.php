<?php

namespace App\Services;

use App\Contracts\DnsMaker;

/**
 * The panel does not publish names here — a person does, at whoever holds the
 * zone.
 *
 * The default, and deliberately so. Automating this means keeping a token on
 * the server that can rewrite where every one of Soran's domains points, and
 * that is a real cost to weigh against thirty seconds of work per shop. It
 * should be a decision, not something that happens because nobody looked.
 */
class ManualDns implements DnsMaker
{
    public function create(string $host, string $address): void
    {
        // Nothing to do, and nothing to pretend.
    }

    public function remove(string $host): array
    {
        return [];
    }

    public function describe(): string
    {
        return 'not published by the panel — add each shop’s record at your DNS provider';
    }

    public function verify(): string
    {
        return $this->describe();
    }

    public function isAutomatic(): bool
    {
        return false;
    }
}
