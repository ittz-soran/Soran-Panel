<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Customer;
use App\Models\User;
use App\Services\ShopAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Every shop's copy of the compiled stylesheet — the bug that hid for months.
 *
 * **What happened.** Section 3's shared codebase updates the PHP for every shop
 * at once. It does not update their assets: `shop:provision` gives each shop a
 * COPY of `public/build`, and the shop's front controller points Laravel at
 * that copy. So a pull moved every shop's markup and left every shop's
 * stylesheet alone, and the panel — which cleared their compiled views, ran
 * their migrations and refreshed its OWN borrowed look — never touched it.
 *
 * **Why nothing saw it.** The panel's equivalent failure is loud: its manifest
 * names a file the served folder has not got, so every stylesheet 404s and the
 * screen is visibly unstyled. A shop's old copy agrees with itself. Nothing
 * 404s, nothing throws, the page renders in full and looks right — it is
 * wearing last month's stylesheet, so only markup written since is unstyled.
 * It was found by a shopkeeper saying four new charts looked like black boxes.
 *
 * So the test that matters most here is `test_an_old_copy_is_internally_
 * consistent_and_still_behind`: the trap is that everything the old checks
 * looked at is fine.
 */
class ShopAssetsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->root = sys_get_temp_dir().'/shop-assets-'.bin2hex(random_bytes(6));

        mkdir($this->root.'/shop-system/public', 0777, true);
        mkdir($this->root.'/public_html', 0777, true);

        config([
            'panel.shops.shared_artisan' => $this->root.'/shop-system/artisan',
            'panel.shops.public_root' => $this->root.'/public_html',
        ]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root ?? '');

        parent::tearDown();
    }

    public function test_it_takes_the_build_from_beside_the_shared_artisan(): void
    {
        $this->assertSame(
            $this->root.'/shop-system/public/build',
            app(ShopAssets::class)->source(),
        );
    }

    /**
     * The trap, written down.
     *
     * The shop's manifest and the shop's files agree with each other, so every
     * check that asks "is anything missing?" says no. The only question that
     * finds it is "is this the build the shared codebase is holding?".
     */
    public function test_an_old_copy_is_internally_consistent_and_still_behind(): void
    {
        $shop = $this->shop('Halabja Phone', wearing: 'old');
        $this->shopSystemBuilt('new');

        // Nothing is missing. This is what made it invisible.
        $this->assertFileExists($shop->public_path.'/build/manifest.json');
        $this->assertFileExists($shop->public_path.'/build/assets/app-old.css');

        $behind = app(ShopAssets::class)->behind();

        $this->assertCount(1, $behind);
        $this->assertSame('Halabja Phone', $behind[0]->name);
    }

    public function test_a_shop_already_wearing_the_shared_build_is_not_behind(): void
    {
        $this->shop('Hawler Computer', wearing: 'old');
        $this->shopSystemBuilt('new');

        app(ShopAssets::class)->refreshEvery();

        $this->assertSame([], app(ShopAssets::class)->behind());
    }

    public function test_it_gives_every_shop_the_shared_build(): void
    {
        $one = $this->shop('One', wearing: 'old');
        $two = $this->shop('Two', wearing: 'older still');
        $this->shopSystemBuilt('new');

        $done = app(ShopAssets::class)->refreshEvery();

        $this->assertCount(2, $done['written']);
        $this->assertSame([], $done['stubborn']);
        $this->assertSame('new', $this->worn($one));
        $this->assertSame('new', $this->worn($two));
    }

    /** A suspended shop comes back, and the day it comes back must not be the day it breaks. */
    public function test_a_suspended_shop_is_not_left_behind(): void
    {
        $shop = $this->shop('Paused', wearing: 'old', state: Customer::SUSPENDED);
        $this->shopSystemBuilt('new');

        app(ShopAssets::class)->refreshEvery();

        $this->assertSame('new', $this->worn($shop));
    }

    /**
     * ⚠️ The rule the whole design turns on.
     *
     * A source that is not a finished build must never be allowed to take a
     * working stylesheet away. `BuildFolder::replace` copies in beside the old
     * one and swaps with a rename for exactly this, and the check runs before
     * any shop is opened at all.
     */
    public function test_an_unusable_source_leaves_every_shop_wearing_what_it_had(): void
    {
        $shop = $this->shop('Halabja Phone', wearing: 'old');

        // A folder called build with nothing finished in it — the state that
        // broke the live panel, which passes is_dir and fails everything after.
        mkdir($this->root.'/shop-system/public/build', 0777, true);

        try {
            app(ShopAssets::class)->refreshEvery();
            $this->fail('refreshEvery() accepted a folder that is not a finished build.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No shop was changed', $e->getMessage());
        }

        $this->assertSame('old', $this->worn($shop));
    }

    public function test_a_source_that_is_not_there_at_all_changes_nothing(): void
    {
        $shop = $this->shop('Halabja Phone', wearing: 'old');

        try {
            app(ShopAssets::class)->refreshEvery();
            $this->fail('refreshEvery() accepted a source that is not there.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('There is no [', $e->getMessage());
        }

        $this->assertSame('old', $this->worn($shop));
        $this->assertSame([], app(ShopAssets::class)->behind(), 'A source nobody can copy makes nobody behind.');
    }

    /**
     * One shop's failure is its own.
     *
     * The shop after it in the loop must still be fixed — the same reasoning as
     * `clearEveryShop`, and the reason this reports rather than throws.
     */
    public function test_a_shop_whose_folder_is_gone_does_not_stop_the_others(): void
    {
        $gone = Customer::factory()->create([
            'name' => 'Vanished',
            'public_path' => $this->root.'/public_html/not-there',
        ]);
        $fine = $this->shop('Still Here', wearing: 'old');
        $this->shopSystemBuilt('new');

        $done = app(ShopAssets::class)->refreshEvery();

        $this->assertSame('new', $this->worn($fine));

        // Not "stubborn" — a shop with no folder on the disk is the Health
        // screen's business, and is never counted as behind on its stylesheet.
        $this->assertSame([], $done['stubborn']);
        $this->assertNotContains($gone->name, array_column(
            array_map(fn ($c) => ['name' => $c->name], app(ShopAssets::class)->behind()), 'name',
        ));
    }

    public function test_it_leaves_nothing_half_written_behind(): void
    {
        $shop = $this->shop('One', wearing: 'old');
        $this->shopSystemBuilt('new');

        app(ShopAssets::class)->refreshEvery();

        $this->assertDirectoryDoesNotExist($shop->public_path.'/build.incoming');
        $this->assertDirectoryDoesNotExist($shop->public_path.'/build.previous');
    }

    public function test_it_writes_down_that_it_happened(): void
    {
        $this->shop('One', wearing: 'old');
        $this->shopSystemBuilt('new');

        app(ShopAssets::class)->refreshEvery();

        $this->assertDatabaseHas('actions', ['action' => 'shops.assets_copied']);
        $this->assertSame(1, Action::where('action', 'shops.assets_copied')->first()->detail['written']);
    }

    public function test_nothing_is_written_down_when_there_was_nothing_to_do(): void
    {
        $this->shopSystemBuilt('new');

        app(ShopAssets::class)->refreshEvery();

        $this->assertDatabaseMissing('actions', ['action' => 'shops.assets_copied']);
    }

    public function test_the_command_says_which_shops_are_behind_and_can_rehearse(): void
    {
        $shop = $this->shop('Halabja Phone', wearing: 'old');
        $this->shopSystemBuilt('new');

        $this->artisan('shops:assets --pretend')
            ->expectsOutputToContain('Halabja Phone')
            ->expectsOutputToContain('behind')
            ->assertExitCode(0);

        $this->assertSame('old', $this->worn($shop), 'A rehearsal changed a shop.');

        $this->artisan('shops:assets')->assertExitCode(0);

        $this->assertSame('new', $this->worn($shop));
    }

    public function test_the_command_refuses_a_source_it_cannot_copy(): void
    {
        $shop = $this->shop('Halabja Phone', wearing: 'old');

        $this->artisan('shops:assets')
            ->expectsOutputToContain('There is no [')
            ->assertExitCode(1);

        $this->assertSame('old', $this->worn($shop));
    }

    /** The screen counts them, because nothing else could see them. */
    public function test_the_updates_screen_names_the_shops_that_are_behind(): void
    {
        $this->shop('Halabja Phone', wearing: 'old');
        $this->shopSystemBuilt('new');

        $this->get(route('updates'))
            ->assertOk()
            ->assertSee('1 behind')
            ->assertSee('Halabja Phone');
    }

    public function test_the_screen_button_gives_them_the_build(): void
    {
        $shop = $this->shop('Halabja Phone', wearing: 'old');
        $this->shopSystemBuilt('new');

        $this->post(route('updates.shop-assets'))->assertRedirect();

        $this->assertSame('new', $this->worn($shop));
        $this->get(route('updates'))->assertOk()->assertDontSee('behind</span>', escape: false);
    }

    /**
     * A shop with a build, its manifest and a stylesheet — the state
     * `shop:provision` leaves behind.
     */
    private function shop(string $name, string $wearing, string $state = Customer::ACTIVE): Customer
    {
        $public = $this->root.'/public_html/'.strtolower(str_replace(' ', '-', $name));

        mkdir($public.'/build/assets', 0777, true);
        file_put_contents($public.'/build/manifest.json', json_encode($wearing));
        file_put_contents($public.'/build/assets/app-old.css', 'body{}');

        return Customer::factory()->create([
            'name' => $name,
            'public_path' => $public,
            'status' => $state,
        ]);
    }

    private function worn(Customer $customer): string
    {
        return json_decode((string) @file_get_contents($customer->public_path.'/build/manifest.json'), true) ?? '';
    }

    private function shopSystemBuilt(string $mark): void
    {
        $build = $this->root.'/shop-system/public/build';

        mkdir($build.'/assets', 0777, true);
        file_put_contents($build.'/manifest.json', json_encode($mark));
        file_put_contents($build.'/assets/app-abc123.css', 'body{}');
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.'/'.$entry;

            is_dir($full) && ! is_link($full) ? $this->rmrf($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
