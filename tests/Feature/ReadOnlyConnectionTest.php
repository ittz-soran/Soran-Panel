<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ReadOnlyConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The panel cannot write to a shop's database — PANEL_DOC Section 1, rule 3.
 *
 * The panel connects with the shop's own credentials, because that is the only
 * account there is, and those credentials can do anything. Nothing at the
 * database end says no. So the refusal is in the one object every query passes
 * through, and this holds that every way of writing is closed rather than the
 * three somebody thought of.
 */
class ReadOnlyConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): ReadOnlyConnection
    {
        $live = $this->app['db']->connection();

        return new ReadOnlyConnection(
            $live->getPdo(),
            'a_shops_database',
            $live->getTablePrefix(),
            $live->getConfig(),
        );
    }

    public static function waysOfWriting(): array
    {
        return [
            'insert' => ['insert', ['insert into users (name) values (?)', ['x']]],
            'update' => ['update', ['update users set name = ?', ['x']]],
            'delete' => ['delete', ['delete from users']],
            'statement' => ['statement', ['drop table users']],
            'affectingStatement' => ['affectingStatement', ['update users set name = "x"']],
            'unprepared' => ['unprepared', ['truncate table users']],
            'beginTransaction' => ['beginTransaction', []],
        ];
    }

    #[DataProvider('waysOfWriting')]
    public function test_every_way_of_writing_is_refused(string $method, array $arguments): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('It may only read.');

        $this->connection()->{$method}(...$arguments);
    }

    /** The refusal names the shop, so a stack trace says whose data was nearly touched. */
    public function test_the_refusal_names_the_database_and_the_rule(): void
    {
        try {
            $this->connection()->delete('delete from sales');
            $this->fail('a delete should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('a_shops_database', $e->getMessage());
            $this->assertStringContainsString('delete from sales', $e->getMessage());
            $this->assertStringContainsString('Section 1 rule 3', $e->getMessage());
        }
    }

    /** Reading still works, or the connection would be no use at all. */
    public function test_reading_still_works(): void
    {
        User::factory()->create(['name' => 'Soran']);

        $rows = $this->connection()->select('select name from users');

        $this->assertSame('Soran', $rows[0]->name);
        $this->assertSame('Soran', $this->connection()->selectOne('select name from users')->name);
    }

    /**
     * Nothing reached the server. A refusal that happened after the statement
     * ran would be a report, not a guard.
     */
    public function test_the_write_never_reaches_the_database(): void
    {
        User::factory()->create(['name' => 'Soran']);

        try {
            $this->connection()->update("update users set name = 'changed'");
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('Soran', User::first()->name);
    }
}
