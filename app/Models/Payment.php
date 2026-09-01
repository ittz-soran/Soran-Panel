<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money received — PANEL_DOC Section 5.
 */
#[Fillable([
    'customer_id', 'amount', 'paid_on',
    'covers_from', 'covers_to', 'method', 'reference', 'note', 'recorded_by',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date',
            'covers_from' => 'date',
            'covers_to' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** How many whole months this payment buys. At least one. */
    public function monthsCovered(): int
    {
        return max(1, (int) $this->covers_from->diffInMonths($this->covers_to->copy()->addDay()));
    }
}
