<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\HealthCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthCheck>
 */
class HealthCheckFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'checked_at' => now(),
            'reachable' => true,
            'database_bytes' => 40 * 1024 * 1024,
            'backups_bytes' => 120 * 1024 * 1024,
            'uploads_bytes' => 8 * 1024 * 1024,
            'storage_limit_mb' => 2048,
            'migrations_run' => 29,
            'migrations_total' => 29,
            'last_activity_at' => now()->subHours(2),
            'users_count' => 3,
            'products_count' => 412,
            'sales_count' => 1875,
            'licence_state' => 'valid',
            'data_check_passed' => 17,
            'data_check_total' => 17,
        ];
    }

    /**
     * A check that could not look. Every number null, so it cannot be mistaken
     * for a shop that genuinely has nothing.
     */
    public function unreachable(string $error = 'SQLSTATE[HY000] [2002] Connection refused'): static
    {
        return $this->state(fn () => [
            'reachable' => false,
            'database_bytes' => null,
            'backups_bytes' => null,
            'uploads_bytes' => null,
            'storage_limit_mb' => null,
            'migrations_run' => null,
            'migrations_total' => null,
            'last_activity_at' => null,
            'users_count' => null,
            'products_count' => null,
            'sales_count' => null,
            'licence_state' => null,
            'data_check_passed' => null,
            'data_check_total' => null,
            'error' => $error,
        ]);
    }
}
