<?php

namespace App\Models;

use Database\Factories\HealthCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hourly snapshot of one shop — PANEL_DOC Section 5.
 *
 * Read-only by construction: nothing in the panel updates a snapshot, because
 * a reading that can be corrected afterwards is not a reading. A check that
 * failed is a row with `reachable` false and `error` set, sitting beside
 * yesterday's good numbers rather than replacing them.
 */
#[Fillable([
    'customer_id', 'checked_at', 'reachable',
    'database_bytes', 'backups_bytes', 'uploads_bytes', 'storage_limit_mb',
    'migrations_run', 'migrations_total',
    'last_activity_at', 'users_count', 'products_count', 'sales_count',
    'licence_state', 'data_check_passed', 'data_check_total', 'error',
])]
class HealthCheck extends Model
{
    /** @use HasFactory<HealthCheckFactory> */
    use HasFactory;

    /** `checked_at` is when this happened; a created_at beside it would be the same moment twice. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'reachable' => 'boolean',
            'last_activity_at' => 'datetime',
            'database_bytes' => 'integer',
            'backups_bytes' => 'integer',
            'uploads_bytes' => 'integer',
            'storage_limit_mb' => 'integer',
            'migrations_run' => 'integer',
            'migrations_total' => 'integer',
            'users_count' => 'integer',
            'products_count' => 'integer',
            'sales_count' => 'integer',
            'data_check_passed' => 'integer',
            'data_check_total' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Everything the shop is using, in bytes. Null if the check could not look. */
    public function totalBytes(): ?int
    {
        if (! $this->reachable) {
            return null;
        }

        return (int) $this->database_bytes + (int) $this->backups_bytes + (int) $this->uploads_bytes;
    }

    /**
     * How full the shop is, as a percentage of its limit.
     *
     * Null when there is no limit, or when the check could not look. Not zero:
     * a shop with no limit is not an empty shop, and a screen that draws 0%
     * for both has said something false about one of them.
     */
    public function storagePercent(): ?float
    {
        $used = $this->totalBytes();

        if ($used === null || ! $this->storage_limit_mb) {
            return null;
        }

        return round($used / ($this->storage_limit_mb * 1024 * 1024) * 100, 1);
    }

    /** Whether the shop has migrations it has not run — old code against a new database. */
    public function migrationsPending(): ?int
    {
        if ($this->migrations_run === null || $this->migrations_total === null) {
            return null;
        }

        return max(0, $this->migrations_total - $this->migrations_run);
    }

    /**
     * Whether every Section 10b assertion held.
     *
     * Null when the check could not run them. False is a contradiction in the
     * shop's own data, and PANEL_DOC Section 8 is deliberate that the panel
     * reports it and never repairs it: a contradiction is evidence, and
     * repairing it before it has been read destroys the record of what went
     * wrong.
     */
    public function dataCheckPassed(): ?bool
    {
        if ($this->data_check_total === null) {
            return null;
        }

        return $this->data_check_passed === $this->data_check_total;
    }
}
