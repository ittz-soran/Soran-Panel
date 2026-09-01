<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One shop sold — PANEL_DOC Section 5.
 */
#[Fillable([
    'name', 'contact_name', 'phone', 'email', 'host',
    'shop_home', 'public_path', 'database_name', 'database_user',
    'status', 'monthly_fee', 'storage_limit_mb', 'language', 'started_on', 'notes',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    /** On a trial: running unlicensed, with an end date the panel chases. */
    public const TRIAL = 'trial';

    /** Paid up and licensed. */
    public const ACTIVE = 'active';

    /** Stopped by Soran — not gone, and expected back. */
    public const SUSPENDED = 'suspended';

    /** Finished. Kept, because the licence and payment history outlive them. */
    public const ENDED = 'ended';

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'monthly_fee' => 'integer',
            'storage_limit_mb' => 'integer',
        ];
    }

    public function licences(): HasMany
    {
        return $this->hasMany(Licence::class);
    }

    /**
     * The licence this shop is running on now.
     *
     * The newest one that was actually delivered and has not been revoked —
     * not simply the newest row. A licence issued and never delivered (Section
     * 6 step 7 never confirmed) is not what the shop is running, and treating
     * it as though it were is how a customer who paid stays locked out while
     * the panel shows them as fine.
     */
    public function currentLicence(): HasOne
    {
        /*
         * The filters go INSIDE the subquery, not after it.
         *
         * Chained as ->whereNotNull(...)->latestOfMany(...) they read the same
         * and are not: latestOfMany takes MAX(issued_on) over every licence the
         * customer has, and the filters then throw the winner away if it
         * happens to be revoked or undelivered — leaving null. A shop whose
         * newest licence was revoked would have shown as having no licence at
         * all, while running perfectly well on the one before it.
         *
         * issued_on and then id, because two licences can be issued on the same
         * day — a delivery that failed and was redone is the ordinary case —
         * and MAX(issued_on) alone leaves which one wins to chance.
         */
        return $this->hasOne(Licence::class)->ofMany(
            ['issued_on' => 'max', 'id' => 'max'],
            fn ($query) => $query->whereNotNull('delivered_at')->whereNull('revoked_at'),
        );
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    /** The last time the panel managed to look at this shop, good or bad. */
    public function latestHealthCheck(): HasOne
    {
        return $this->hasOne(HealthCheck::class)->latestOfMany('checked_at');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }

    /** Whether the panel should be doing anything about this shop at all. */
    public function isLive(): bool
    {
        return in_array($this->status, [self::TRIAL, self::ACTIVE], true);
    }

    /** @param  Builder<Customer>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', [self::TRIAL, self::ACTIVE]);
    }

    /**
     * Shops whose licence runs out within the given days — Section 9's Overview.
     *
     * Asked of the customer rather than of the licences table, and this is the
     * difference between a useful Overview and a noisy one. Every renewal
     * leaves the expired licence behind (a renewal is a new row, never an
     * edit), so a shop that has paid faithfully for a year has twelve dead
     * licences, and each of them is "expired" for ever. Counting licences puts
     * that shop on the list twelve times over while nothing is wrong with it.
     *
     * Only what the shop is running NOW counts, and only shops still live: a
     * suspended or ended customer is not something to be chased about a date.
     *
     * Already past counts too. A licence that ran out last week needs Soran
     * more than one running out next week, not less.
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeLicenceExpiringWithin(Builder $query, int $days): void
    {
        $query->live()->whereHas(
            'currentLicence',
            fn ($licence) => $licence
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', now()->addDays($days)),
        );
    }

    /**
     * Paid up to, as the payments say — never as the licence says.
     *
     * A licence is what the shop can run on; a payment is what was actually
     * received. They come apart exactly when it matters: a licence delivered
     * before the money arrived, or money taken for months not yet issued.
     */
    public function paidUpTo(): ?Carbon
    {
        $covered = $this->payments()->max('covers_to');

        return $covered ? Carbon::parse($covered) : null;
    }
}
