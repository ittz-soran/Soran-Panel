<?php

namespace App\Console\Commands;

use App\Contracts\ShopReader;
use App\Models\Customer;
use App\Models\HealthCheck;
use App\Support\ShopReading;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Look at every shop, and write down what was found — PANEL_DOC Section 5.
 *
 * Hourly. A snapshot per shop per run, kept rather than overwritten, because
 * you want to see storage growing over weeks and a failed check must not wipe
 * the last good reading.
 *
 * It never stops early. One shop with a stopped database is a row saying so,
 * and the other five are looked at anyway — a monitor that gives up on the
 * first problem stops being a monitor exactly when it is needed.
 */
class ShopsCheck extends Command
{
    protected $signature = 'shops:check
                            {customer? : One customer, by id or host. Default is every live shop.}
                            {--all : Include suspended and ended shops too}';

    protected $description = 'Read every shop and record a health check';

    public function handle(ShopReader $reader): int
    {
        $customers = $this->customers();

        if ($customers->isEmpty()) {
            $this->components->warn('No shops to look at.');

            return self::SUCCESS;
        }

        $unreachable = 0;

        foreach ($customers as $customer) {
            $reading = null;

            try {
                $reading = $reader->read($customer);

                HealthCheck::create([
                    'customer_id' => $customer->id,
                    'checked_at' => now(),
                    ...$reading->toHealthCheck(),
                ]);
            } catch (Throwable $e) {
                // The reader promises not to throw. If it does anyway, that is
                // this shop's problem and not the next shop's, and the promise
                // being broken is itself worth a row.
                report($e);

                HealthCheck::create([
                    'customer_id' => $customer->id,
                    'checked_at' => now(),
                    'reachable' => false,
                    'error' => 'The check itself failed: '.$e->getMessage(),
                ]);
            }

            if (! $this->report($customer, $reading)) {
                $unreachable++;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Looked at', sprintf(
            '%d shop%s, %d unreachable',
            $customers->count(),
            $customers->count() === 1 ? '' : 's',
            $unreachable,
        ));

        // Zero either way. Shops being down is what this command is for
        // reporting, not a reason for the scheduler to call the run a failure
        // and email Soran about cron instead of about the shop.
        return self::SUCCESS;
    }

    private function report(Customer $customer, ?ShopReading $reading): bool
    {
        if ($reading === null || ! $reading->reachable) {
            $this->components->twoColumnDetail($customer->host, '<fg=red>unreachable</>');

            foreach ($reading?->problems ?? [] as $problem) {
                $this->line('    '.$problem);
            }

            return false;
        }

        $this->components->twoColumnDetail($customer->host, sprintf(
            '<fg=green>%s</> · %s · %d products',
            $reading->licenceState ?? 'licence unknown',
            $this->readable(
                (int) $reading->databaseBytes + (int) $reading->backupsBytes + (int) $reading->uploadsBytes,
            ),
            (int) $reading->productsCount,
        ));

        // Something that went wrong without making the shop unreachable is
        // still worth saying out loud rather than only writing down.
        foreach ($reading->problems as $problem) {
            $this->line('    <fg=yellow>'.$problem.'</>');
        }

        return true;
    }

    private function readable(int $bytes): string
    {
        $size = (float) $bytes;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($size < 1024 || $unit === 'GB') {
                return round($size, 1).' '.$unit;
            }

            $size /= 1024;
        }

        return $bytes.' B';
    }

    /** @return Collection<int, Customer> */
    private function customers(): Collection
    {
        $one = $this->argument('customer');

        if ($one !== null) {
            return Customer::query()
                ->where(fn ($query) => $query->where('id', $one)->orWhere('host', $one))
                ->get();
        }

        return Customer::query()
            ->when(! $this->option('all'), fn ($query) => $query->live())
            ->orderBy('host')
            ->get();
    }
}
