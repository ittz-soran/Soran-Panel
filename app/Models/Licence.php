<?php

namespace App\Models;

use Database\Factories\LicenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One licence, issued once — PANEL_DOC Section 5.
 *
 * A renewal is a new row, never an edit. Nothing here has a setter that moves
 * an expiry date, because a licence is a signed string: changing the date in
 * this table would not change what the shop runs on, and the two would disagree
 * silently for months.
 */
#[Fillable([
    'customer_id', 'licence_id', 'host', 'licence_key',
    'issued_on', 'expires_on', 'delivered_at', 'issued_by',
    'revoked_at', 'revoked_reason',
])]
class Licence extends Model
{
    /** @use HasFactory<LicenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'delivered_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** Null expiry means sold outright, with no end date. */
    public function isPerpetual(): bool
    {
        return $this->expires_on === null;
    }

    /**
     * Whether this licence was confirmed as reaching the shop.
     *
     * Section 6 step 7: the shop is asked what it now thinks, and the answer is
     * shown back. Until that comes true this licence exists in the panel and
     * nowhere else.
     */
    public function wasDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Days until it runs out. Negative once it has. Null if it never does. */
    public function daysLeft(?Carbon $from = null): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        return ($from ?? now())->startOfDay()->diffInDays($this->expires_on->startOfDay(), false);
    }

    /** @param  Builder<Licence>  $query */
    public function scopeDelivered(Builder $query): void
    {
        $query->whereNotNull('delivered_at')->whereNull('revoked_at');
    }

    /**
     * Licences running out within the given days.
     *
     * This counts LICENCES, and is not what Section 9's Overview asks: a shop
     * that renews every month leaves a dead licence behind each time, and all
     * of them stay expired for ever. Use Customer::licenceExpiringWithin() for
     * "which shops need me", which asks only what each shop is running now.
     *
     * Perpetual licences are excluded rather than treated as far-future: they
     * never need chasing, and a null date sorting as "soonest" would put every
     * outright sale at the top of the list that means "do something".
     */
    public function scopeExpiringWithin(Builder $query, int $days): void
    {
        $query->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', now()->addDays($days))
            ->delivered();
    }
}
