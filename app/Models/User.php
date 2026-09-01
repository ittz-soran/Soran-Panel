<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * A panel operator — PANEL_DOC Section 5.
 *
 * Soran is the only one for now. The shape allows staff later, which is why
 * `role` exists at all rather than every account simply being an admin: adding
 * the column afterwards means a migration against live accounts, and deciding
 * afterwards means guessing which existing rows were meant to be which.
 *
 * The authenticator columns and the recovery-code handling are the shop
 * system's, reused as Section 5 says. What is deliberately NOT reused is the
 * shop system's permission table and cost-visibility rules: those answer
 * questions about a shop's trade, and the panel has no trade in it.
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'theme'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, SoftDeletes;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_STAFF = 'staff';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',

            // Encrypted at rest, for the shop system's reason: a database dump
            // that hands over both the password hashes and the thing that
            // resets them has handed over everything the panel can reach — and
            // what the panel can reach is every customer's install.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Whether this person has a way back in without the post office.
     *
     * Confirmed, not merely generated: a secret nobody has typed a code back
     * from is worse than none at all, because it looks like a way in on a
     * screen and is not one.
     */
    public function hasAuthenticator(): bool
    {
        // Asked through the attribute bag rather than as a property, because a
        // model loaded with a partial select does not carry these columns and
        // reading them would throw.
        $attributes = $this->getAttributes();

        return ! empty($attributes['two_factor_secret'])
            && ! empty($attributes['two_factor_confirmed_at']);
    }

    /**
     * Eight one-time codes, for the phone that is lost, wiped, or in a pocket
     * in another city.
     *
     * Without these an authenticator is a second way to be locked out rather
     * than a way back in — which is the whole failure it exists to prevent.
     *
     * @return list<string>
     */
    public static function newRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /**
     * Spend one, if it is real.
     *
     * Single use, and removed inside the same call that accepts it, so a code
     * read over somebody's shoulder is worth nothing by the time they type it.
     */
    public function spendRecoveryCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        $codes = $this->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $code)) {
                unset($codes[$index]);

                $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }
}
