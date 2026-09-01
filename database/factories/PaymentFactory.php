<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'amount' => 50000,
            'paid_on' => now()->toDateString(),
            'covers_from' => now()->startOfMonth()->toDateString(),
            'covers_to' => now()->startOfMonth()->addMonth()->subDay()->toDateString(),
            'method' => 'cash',
        ];
    }

    /** One payment buying several months at once. */
    public function covering(int $months): static
    {
        return $this->state(fn (array $attributes) => [
            'covers_to' => Carbon::parse($attributes['covers_from'])
                ->addMonths($months)->subDay()->toDateString(),
        ]);
    }
}
