<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Action>
 */
class ActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            'action' => 'storage_limit.changed',
            'detail' => ['from' => 1024, 'to' => 2048],
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ];
    }

    /** The panel's own doing, belonging to no customer. */
    public function withoutCustomer(): static
    {
        return $this->state(fn () => ['customer_id' => null]);
    }
}
