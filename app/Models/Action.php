<?php

namespace App\Models;

use Database\Factories\ActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the panel did, and who told it to — PANEL_DOC Section 5.
 *
 * Append-only. `UPDATED_AT` is null so Eloquent never writes one, and nothing
 * here offers a way to change a row: a log somebody can edit is not a log.
 */
#[Fillable(['customer_id', 'user_id', 'action', 'detail', 'ip_address'])]
class Action extends Model
{
    /** @use HasFactory<ActionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Write down what the panel just did — PANEL_DOC Section 1, rule 2:
     * "anything that reaches into a customer's install leaves a record with a
     * name on it."
     *
     * One method, so that no caller has to remember to fetch the operator or
     * the IP address. The signed-in user and the request are read here rather
     * than passed in, because a caller that has to supply them is a caller that
     * can supply the wrong ones — or, on the day it matters, none at all.
     *
     * @param  array<string, mixed>|null  $detail  from → to, as JSON
     */
    public static function record(string $action, ?Customer $customer = null, ?array $detail = null): self
    {
        return self::create([
            'customer_id' => $customer?->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'detail' => $detail,
            'ip_address' => request()->ip(),
        ]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
