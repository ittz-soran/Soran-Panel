<?php

namespace Tests\Feature;

use App\Contracts\ShopReader;
use App\Models\Action;
use App\Models\Customer;
use App\Models\User;
use App\Services\LocalShopReader;
use App\Support\ShopEnvironment;
use App\Support\ShopReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sections — PANEL_DOC Section 6b, PROJECT_DOC Section 8h.
 *
 * Soran, 2026-10-19: *"i want manage from panel"*, for Hamza — once
 * Halabja-phone — which needs no stock rooms, second-hand or repairs.
 *
 * A real folder with a real `.env`, written by the real writer. The shop is a
 * stand-in that reads that `.env` the way the shop system does — OFF wins, a
 * section in testing is off until ON names it — so asking before and after a
 * switch is a real round trip through the file. The shop system's own suite
 * holds the shop's half; `shop:sections` was also run against it end to end.
 */
class SectionsTest extends TestCase
{
    use RefreshDatabase;

    private string $home;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->home = sys_get_temp_dir().'/shop-sections-'.bin2hex(random_bytes(6));
        mkdir($this->home, 0755, true);
        file_put_contents($this->home.'/.env', implode("\n", [
            'APP_NAME="Hamza"',
            'DB_DATABASE=hamza_shop',
            '# Sections this shop does without',
            'SHOP_SECTIONS_OFF=',
            'SHOP_SECTIONS_ON=',
        ])."\n");

        $this->customer = Customer::factory()->create([
            'name' => 'Hamza',
            'host' => 'hamza.soranstore.com',
            'shop_home' => $this->home,
            'status' => Customer::ACTIVE,
        ]);

        $this->shop();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->home.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->home);

        parent::tearDown();
    }

    /**
     * The stand-in shop.
     *
     * @param  array<string, string>  $holds  what holds a section, in the shop's words
     * @param  bool  $answers  false: a shop on code older than the switch
     * @param  bool  $checked  false: its database did not answer
     * @param  bool  $stubborn  true: it ignores its .env, so a switch never lands
     */
    private function shop(array $holds = [], bool $answers = true, bool $checked = true, bool $stubborn = false): void
    {
        $this->swap(ShopReader::class, new class($this->home, $holds, $answers, $checked, $stubborn) implements ShopReader
        {
            private const SECTIONS = [
                'stock_rooms' => ['Stock rooms', false],
                'second_hand' => ['Second-hand', false],
                'repairs' => ['Repairs', false],
                'assemblies' => ['Take apart & build', false],
                'swaps' => ['Swaps', false],
                'currencies' => ['Currencies', false],
                'instalments' => ['Instalments', true],
            ];

            private ?string $firstEnv = null;

            public function __construct(
                private string $home,
                private array $holds,
                private bool $answers,
                private bool $checked,
                private bool $stubborn,
            ) {}

            public function read(Customer $customer): ShopReading
            {
                return new ShopReading(reachable: true);
            }

            public function licenceState(Customer $customer): ?string
            {
                return 'valid';
            }

            public function sections(Customer $customer): ?array
            {
                if (! $this->answers) {
                    return null;
                }

                $env = (string) file_get_contents($this->home.'/.env');
                $this->firstEnv ??= $env;

                if ($this->stubborn) {
                    $env = $this->firstEnv;
                }

                $line = fn (string $name) => preg_match('/^'.$name.'=(.*)$/m', $env, $m)
                    ? array_values(array_filter(array_map('trim', explode(',', $m[1])))) : [];

                [$off, $on] = [$line('SHOP_SECTIONS_OFF'), $line('SHOP_SECTIONS_ON')];
                $rows = [];

                foreach (self::SECTIONS as $key => [$name, $testing]) {
                    $isOn = in_array($key, $off, true) ? false : (! $testing || in_array($key, $on, true));

                    $rows[] = [
                        'key' => $key, 'name' => $name, 'on' => $isOn, 'testing' => $testing,
                        'why' => in_array($key, $off, true) ? 'SHOP_SECTIONS_OFF'
                            : ($testing && in_array($key, $on, true) ? 'SHOP_SECTIONS_ON' : 'default'),
                        'holds' => $this->checked ? ($this->holds[$key] ?? null) : null,
                    ];
                }

                return [
                    'sections' => $rows,
                    'unknown' => array_values(array_diff([...$off, ...$on], array_keys(self::SECTIONS))),
                    'holds_checked' => $this->checked,
                ];
            }
        });
    }

    private function env(): string
    {
        return (string) file_get_contents($this->home.'/.env');
    }

    private function switchIt(string $section, bool $on): TestResponse
    {
        return $this->from(route('customers.show', $this->customer))
            ->post(route('customers.sections', $this->customer), ['section' => $section, 'on' => $on ? 1 : 0]);
    }

    // ---- his case ----------------------------------------------------------

    public function test_hamza_loses_stock_rooms_second_hand_and_repairs(): void
    {
        foreach (['stock_rooms', 'second_hand', 'repairs'] as $section) {
            $this->switchIt($section, false)->assertSessionHas('success');
        }

        $this->assertStringContainsString("\nSHOP_SECTIONS_OFF=stock_rooms,second_hand,repairs\n", $this->env());

        // The rest of the file is exactly as it was, comments included.
        $this->assertStringContainsString('APP_NAME="Hamza"', $this->env());
        $this->assertStringContainsString('# Sections this shop does without', $this->env());
        $this->assertFileExists($this->home.'/.env.bak');

        $this->assertSame('stock_rooms,second_hand,repairs', $this->customer->fresh()->sections_off);
    }

    /** Section 7: logged, from → to, with what the shop said. */
    public function test_every_switch_is_logged_with_the_shops_answer(): void
    {
        $this->switchIt('repairs', false);

        $logged = Action::where('action', 'section.switched')->sole();

        $this->assertSame(
            ['section' => 'repairs', 'from' => 'on', 'to' => 'off', 'shop_says' => 'off'],
            $logged->detail,
        );
        $this->assertSame($this->customer->id, $logged->customer_id);
        $this->assertSame(auth()->id(), $logged->user_id);
    }

    public function test_switching_back_on_takes_it_out_of_the_line(): void
    {
        $this->switchIt('repairs', false);
        $this->switchIt('swaps', false);
        $this->switchIt('repairs', true)->assertSessionHas('success');

        $this->assertStringContainsString("\nSHOP_SECTIONS_OFF=swaps\n", $this->env());
        $this->assertSame('swaps', $this->customer->fresh()->sections_off);
    }

    public function test_switching_to_what_it_already_is_writes_and_logs_nothing(): void
    {
        $this->switchIt('repairs', true)->assertSessionHas('success');

        $this->assertFileDoesNotExist($this->home.'/.env.bak');
        $this->assertSame(0, Action::where('action', 'section.switched')->count());
    }

    // ---- refused, with the shop's own reason -------------------------------

    public function test_a_section_the_shop_says_is_held_is_not_switched_off(): void
    {
        $this->shop(holds: ['repairs' => "2 repair jobs are open. A customer's device is in the shop."]);

        $this->switchIt('repairs', false)
            ->assertSessionHas('warning', fn (?string $said) => str_contains((string) $said, '2 repair jobs are open'));

        $this->assertStringContainsString("\nSHOP_SECTIONS_OFF=\n", $this->env());
        $this->assertFileDoesNotExist($this->home.'/.env.bak');
        $this->assertSame(0, Action::where('action', 'section.switched')->count());
    }

    /** ⚠️ "Could not ask" is not "nothing holds it". */
    public function test_nothing_is_switched_off_when_the_shop_could_not_check(): void
    {
        $this->shop(checked: false);

        $this->switchIt('stock_rooms', false)->assertSessionHas('warning');

        $this->assertFileDoesNotExist($this->home.'/.env.bak');
    }

    /** Switching ON needs no such check — nothing can be stranded by it. */
    public function test_a_held_shop_can_still_have_a_section_switched_on(): void
    {
        file_put_contents($this->home.'/.env', str_replace('SHOP_SECTIONS_OFF=', 'SHOP_SECTIONS_OFF=swaps', $this->env()));
        $this->shop(checked: false);

        $this->switchIt('swaps', true)->assertSessionHas('success');

        $this->assertStringContainsString("\nSHOP_SECTIONS_OFF=\n", $this->env());
    }

    public function test_a_shop_that_does_not_answer_is_not_touched(): void
    {
        $this->shop(answers: false);

        $this->switchIt('repairs', false)
            ->assertSessionHas('warning', fn (?string $said) => str_contains((string) $said, 'did not say which sections'));

        $this->assertFileDoesNotExist($this->home.'/.env.bak');
    }

    /** A name is only ever matched against the shop's own list. */
    public function test_a_name_the_shop_does_not_have_is_refused(): void
    {
        $this->switchIt('repair', false)
            ->assertSessionHas('warning', fn (?string $said) => str_contains((string) $said, 'no section called [repair]'));

        $this->assertFileDoesNotExist($this->home.'/.env.bak');
    }

    /** ⚠️ The file is not the answer — the shop is. */
    public function test_a_shop_that_ignores_the_new_lines_is_reported_not_believed(): void
    {
        $this->shop(stubborn: true);

        $this->switchIt('repairs', false)
            ->assertSessionHas('warning', fn (?string $said) => str_contains((string) $said, 'still says Repairs is on'));

        // The panel's copy is what the shop said, not what was meant.
        $this->assertSame('', $this->customer->fresh()->sections_off);
        $this->assertSame('on', Action::where('action', 'section.switched')->sole()->detail['shop_says']);
    }

    // ---- what the file held before ----------------------------------------

    /** A hand-typed name that is no section is shown, and dropped by the next write. */
    public function test_a_name_typed_by_hand_is_shown_then_dropped(): void
    {
        file_put_contents($this->home.'/.env', str_replace('SHOP_SECTIONS_OFF=', 'SHOP_SECTIONS_OFF=repair', $this->env()));

        $this->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertSee('In its .env but not a section: repair');

        $this->switchIt('repairs', false)->assertSessionHas('success');

        $this->assertStringContainsString("\nSHOP_SECTIONS_OFF=repairs\n", $this->env());
    }

    // ---- a page tried on one shop first ------------------------------------

    public function test_a_section_in_testing_goes_in_the_on_line(): void
    {
        $this->switchIt('instalments', true)->assertSessionHas('success');

        $this->assertStringContainsString("\nSHOP_SECTIONS_ON=instalments\n", $this->env());
        $this->assertStringContainsString("\nSHOP_SECTIONS_OFF=\n", $this->env());
        $this->assertSame('instalments', $this->customer->fresh()->sections_on);

        $this->switchIt('instalments', false)->assertSessionHas('success');

        $this->assertStringContainsString("\nSHOP_SECTIONS_ON=\n", $this->env());

        // Off is its default, so it is never written into the OFF line —
        // where it would stay off even after a later update released it.
        $this->assertStringContainsString("\nSHOP_SECTIONS_OFF=\n", $this->env());
    }

    // ---- the card ----------------------------------------------------------

    public function test_the_card_shows_what_the_shop_says_and_why_a_switch_is_disabled(): void
    {
        file_put_contents($this->home.'/.env', str_replace('SHOP_SECTIONS_OFF=', 'SHOP_SECTIONS_OFF=second_hand', $this->env()));
        $this->shop(holds: ['stock_rooms' => '14 units are in Back room. Move them to the main room first.']);

        $html = $this->get(route('customers.show', $this->customer))->assertOk()->getContent();

        $this->assertStringContainsString('id="sections"', $html);
        $this->assertStringContainsString('Cannot be switched off: 14 units are in Back room.', $html);
        $this->assertMatchesRegularExpression('/value="stock_rooms">.*?<button type="submit" disabled/s', $html);
        $this->assertMatchesRegularExpression('/value="second_hand">\s*<input type="hidden" name="on" value="1">/', $html);
        $this->assertStringContainsString('testing', $html);
    }

    public function test_a_shop_that_cannot_answer_gets_no_switches(): void
    {
        $this->shop(answers: false);

        $this->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertSee('The shop did not say which sections it has')
            ->assertDontSee(route('customers.sections', $this->customer));
    }

    public function test_a_removed_shop_has_no_card_and_cannot_be_switched(): void
    {
        $this->customer->delete();

        $this->get(route('customers.show', $this->customer))->assertOk()->assertDontSee('id="sections"', false);

        // Like every route that changes a shop, it does not bind a removed one.
        $this->switchIt('repairs', false)->assertNotFound();
        $this->assertFileDoesNotExist($this->home.'/.env.bak');
    }

    // ---- the real reader, against a stand-in artisan -----------------------

    private function artisanPrinting(string $output, int $exit = 0): void
    {
        file_put_contents($this->home.'/artisan', '<?php echo '.var_export($output, true).', PHP_EOL; exit('.$exit.');');
    }

    public function test_the_reader_takes_the_shops_json_as_it_prints_it(): void
    {
        $this->artisanPrinting(json_encode([
            'sections' => [['key' => 'repairs', 'name' => 'Repairs', 'on' => false, 'why' => 'SHOP_SECTIONS_OFF', 'testing' => false, 'holds' => null]],
            'unknown' => ['repair'],
            'holds_checked' => true,
        ]));

        $answer = app(LocalShopReader::class)->sections($this->customer);

        $this->assertSame('repairs', $answer['sections'][0]['key']);
        $this->assertFalse($answer['sections'][0]['on']);
        $this->assertSame(['repair'], $answer['unknown']);
        $this->assertTrue($answer['holds_checked']);
    }

    /** A shop on code older than the switch is no answer — not a shop with nothing off. */
    public function test_a_shop_on_older_code_is_no_answer(): void
    {
        $this->artisanPrinting('  ERROR  Command "shop:sections" is not defined.', 1);

        $this->assertNull(app(LocalShopReader::class)->sections($this->customer));
    }

    /** ⚠️ Half an answer is not read as "every section on". */
    public function test_half_an_answer_is_no_answer(): void
    {
        $this->artisanPrinting('{"sections":[]}');

        $this->assertNull(app(LocalShopReader::class)->sections($this->customer));
    }

    // ---- the panel's own environment ---------------------------------------

    /**
     * ⚠️ Dotenv never overrides what is already set, so a line in the panel's
     * own environment would beat the shop's .env. It is stripped like every
     * other framework key before a shop's artisan runs.
     */
    public function test_the_panels_own_lines_never_reach_a_shop(): void
    {
        $_ENV['SHOP_SECTIONS_OFF'] = 'repairs';
        $_ENV['SHOP_SECTIONS_ON'] = 'instalments';

        try {
            $env = ShopEnvironment::withoutThePanel();

            $this->assertFalse($env['SHOP_SECTIONS_OFF']);
            $this->assertFalse($env['SHOP_SECTIONS_ON']);
        } finally {
            unset($_ENV['SHOP_SECTIONS_OFF'], $_ENV['SHOP_SECTIONS_ON']);
        }
    }
}
