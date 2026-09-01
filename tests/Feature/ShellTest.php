<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The shell — PANEL_DOC Section 9.
 */
class ShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Overview's empty state, now that it is the real screen rather than
     * the scaffold's placeholder. Still the same point: with nothing recorded
     * it says so, because three zeroes would read as "nothing needs you", which
     * is a different and untrue statement.
     */
    public function test_the_overview_says_when_there_is_nothing_recorded(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/overview')
            ->assertOk()
            ->assertSee('No customers yet')
            ->assertDontSee('Nothing needs you this week');
    }

    /**
     * Section 9's eight pages are all named from the first day, so the shape of
     * the thing being built is on the screen. The ones without a route are
     * drawn as text, not links — Section 7: the reason belongs on the screen
     * before the press, not discovered after it.
     */
    public function test_the_sidebar_names_the_pages_that_do_not_exist_yet_without_linking_them(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/overview');

        foreach (Navigation::items() as $item) {
            $response->assertSee($item['label']);

            if ($item['route'] === null) {
                $response->assertSee('Not built yet — '.$item['step']);
            }
        }
    }

    /** Every route the sidebar offers has to exist, or the shell 500s on itself. */
    public function test_every_navigation_route_is_real(): void
    {
        foreach (Navigation::items() as $item) {
            if ($item['route'] !== null) {
                $this->assertTrue(
                    Route::has($item['route']),
                    "The sidebar links to route [{$item['route']}], which does not exist.",
                );
            }
        }
    }

    /**
     * Without an authenticator there is no way back into the panel, so the
     * account menu says so rather than leaving it to be discovered on the day
     * the password is forgotten.
     */
    public function test_an_account_with_no_authenticator_is_told_so_in_the_menu(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/overview')
            ->assertSee('Authenticator')
            ->assertSee('off');

        $this->actingAs(User::factory()->withAuthenticator()->create())
            ->get('/overview')
            ->assertDontSee('badge text-bg-warning', false);
    }

    /**
     * The first draft showed a success message as a toast AND as an in-page
     * alert, so the same sentence appeared twice on screen at once. The shell
     * toasts what worked; the guest screens, which have no toast container,
     * show it inline instead.
     */
    public function test_a_success_message_is_shown_once_in_the_shell(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withSession(['success' => 'The unique sentence.'])
            ->get('/overview')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'The unique sentence.'));
    }

    public function test_a_success_message_is_shown_once_on_a_guest_screen(): void
    {
        $html = $this->withSession(['success' => 'The unique sentence.'])
            ->get('/login')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'The unique sentence.'));
    }
}
