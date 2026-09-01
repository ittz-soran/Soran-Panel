<?php

namespace App\Support;

/**
 * Everything one look at one shop found — PANEL_DOC Section 5's `health_checks`
 * row, before it is a row.
 *
 * Every figure is nullable and every one defaults to null, deliberately. A
 * check that could not ask has to stay distinguishable from a shop that
 * genuinely has nothing: zero products is a broken install, "we do not know" is
 * a broken check, and a screen that draws both as 0 has said something false
 * about one of them.
 */
final class ShopReading
{
    /**
     * @param  list<string>  $problems  What went wrong, if anything. Every step
     *                                  is attempted even when an earlier one
     *                                  failed: a shop whose database is down
     *                                  can still say how much disk it is using,
     *                                  and that is worth recording.
     */
    public function __construct(
        public readonly bool $reachable = false,
        public readonly ?int $databaseBytes = null,
        public readonly ?int $backupsBytes = null,
        public readonly ?int $uploadsBytes = null,
        public readonly ?int $storageLimitMb = null,
        public readonly ?int $migrationsRun = null,
        public readonly ?int $migrationsTotal = null,
        public readonly ?\DateTimeInterface $lastActivityAt = null,
        public readonly ?int $usersCount = null,
        public readonly ?int $productsCount = null,
        public readonly ?int $salesCount = null,
        public readonly ?string $licenceState = null,
        public readonly ?int $dataCheckPassed = null,
        public readonly ?int $dataCheckTotal = null,
        public readonly array $problems = [],
    ) {}

    /** As a `health_checks` row. */
    public function toHealthCheck(): array
    {
        return [
            'reachable' => $this->reachable,
            'database_bytes' => $this->databaseBytes,
            'backups_bytes' => $this->backupsBytes,
            'uploads_bytes' => $this->uploadsBytes,
            'storage_limit_mb' => $this->storageLimitMb,
            'migrations_run' => $this->migrationsRun,
            'migrations_total' => $this->migrationsTotal,
            'last_activity_at' => $this->lastActivityAt,
            'users_count' => $this->usersCount,
            'products_count' => $this->productsCount,
            'sales_count' => $this->salesCount,
            'licence_state' => $this->licenceState,
            'data_check_passed' => $this->dataCheckPassed,
            'data_check_total' => $this->dataCheckTotal,
            'error' => $this->problems === [] ? null : implode("\n", $this->problems),
        ];
    }
}
