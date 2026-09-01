<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Licence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Licence>
 */
class LicenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),

            // The shape `licence:issue` prints — K7QP-3MZX.
            'licence_id' => Str::upper(Str::random(4).'-'.Str::random(4)),
            'host' => fn (array $attributes) => Customer::find($attributes['customer_id'])?->host,
            'licence_key' => 'eyJ'.Str::random(120).'.'.Str::random(200),
            'issued_on' => now()->subMonth()->toDateString(),
            'expires_on' => now()->addMonth()->toDateString(),
            'delivered_at' => now()->subMonth(),
        ];
    }

    /** Issued, but never confirmed as reaching the shop — Section 6 step 7. */
    public function undelivered(): static
    {
        return $this->state(fn () => ['delivered_at' => null]);
    }

    /** Sold outright, with no end date. */
    public function perpetual(): static
    {
        return $this->state(fn () => ['expires_on' => null]);
    }

    public function expiringIn(int $days): static
    {
        return $this->state(fn () => ['expires_on' => now()->addDays($days)->toDateString()]);
    }

    public function revoked(string $reason = 'replaced'): static
    {
        return $this->state(fn () => [
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }
}
