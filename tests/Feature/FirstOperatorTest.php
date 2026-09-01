<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * There is no /register route, so the seeder is the only way into a freshly
 * migrated panel. If it is wrong, the panel cannot be got into at all.
 */
class FirstOperatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_operator_named_in_the_environment(): void
    {
        config()->set('panel.first_operator', [
            'name' => 'Soran',
            'email' => 'soran@soranstore.com',
            'password' => 'a-first-password',
        ]);

        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'soran@soranstore.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Soran', $user->name);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('a-first-password', $user->password));

        // And with no authenticator, which is why the screen nags about it.
        $this->assertFalse($user->hasAuthenticator());
    }

    public function test_it_makes_nothing_when_the_environment_is_empty(): void
    {
        config()->set('panel.first_operator', ['name' => 'Soran', 'email' => null, 'password' => null]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }

    /**
     * A deploy script that re-seeds must not quietly put the password back to
     * whatever the .env said months ago.
     */
    public function test_seeding_twice_does_not_reset_the_password(): void
    {
        config()->set('panel.first_operator', [
            'name' => 'Soran',
            'email' => 'soran@soranstore.com',
            'password' => 'the-first-password',
        ]);

        $this->seed(DatabaseSeeder::class);

        User::where('email', 'soran@soranstore.com')
            ->update(['password' => Hash::make('changed-since')]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check(
            'changed-since',
            User::where('email', 'soran@soranstore.com')->value('password'),
        ));
    }
}
