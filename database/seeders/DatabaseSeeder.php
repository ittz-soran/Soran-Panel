<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The first operator, and nothing else.
 *
 * There is no /register route (routes/auth.php says why), so without this there
 * is no way to get into a freshly migrated panel at all. It reads the account
 * out of the environment rather than hardcoding one, because a seeder with a
 * known password in it is a known password on whatever it is run against.
 *
 * Running it twice does not make a second account or reset the first — a
 * deploy script that re-seeds must not quietly put the password back to what
 * the .env said months ago.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = config('panel.first_operator.email');
        $password = config('panel.first_operator.password');

        if (! $email || ! $password) {
            $this->command?->warn(
                'No operator seeded: set PANEL_ADMIN_EMAIL and PANEL_ADMIN_PASSWORD in .env first.'
            );

            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Operator {$email} already exists — left alone.");

            return;
        }

        User::create([
            'name' => config('panel.first_operator.name'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->command?->info("Operator {$email} created. Set up the authenticator at once — it is the only way back in.");
    }
}
