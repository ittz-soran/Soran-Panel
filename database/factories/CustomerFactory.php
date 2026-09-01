<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = Str::lower($this->faker->unique()->bothify('shop-####'));

        return [
            'name' => $this->faker->company(),
            'contact_name' => $this->faker->name(),
            'phone' => '0750'.$this->faker->numberBetween(1000000, 9999999),
            'email' => $this->faker->unique()->safeEmail(),
            'host' => $slug.'.soranstore.com',
            'shop_home' => '/home/soransto/shops/'.$slug,
            'public_path' => '/home/soransto/public_html/'.$slug,
            'database_name' => str_replace('-', '_', $slug).'_shop',
            'database_user' => str_replace('-', '_', $slug).'_user',
            'status' => Customer::ACTIVE,
            'monthly_fee' => $this->faker->numberBetween(25, 100) * 1000,
            'storage_limit_mb' => 2048,
            'language' => 'ckb',
            'started_on' => now()->subMonths(3)->toDateString(),
        ];
    }

    public function trial(): static
    {
        return $this->state(fn () => ['status' => Customer::TRIAL]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Customer::SUSPENDED]);
    }

    public function ended(): static
    {
        return $this->state(fn () => ['status' => Customer::ENDED]);
    }
}
