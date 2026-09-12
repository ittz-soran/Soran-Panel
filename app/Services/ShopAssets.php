<?php

namespace App\Services;

use App\Models\Action;
use App\Models\Customer;
use App\Support\BuildFolder;
use RuntimeException;
use Throwable;

/**
 * Every shop's copy of the compiled stylesheet — the half of Section 3 that
 * was never written.
 *
 * **The bug, in the words of the shopkeeper who found it: "may be css not work
 * good on new sections".**
 *
 * Section 3 chose one codebase for many shops so that an update is one upload
 * rather than one per customer. That is true of the PHP. It is not true of the
 * compiled assets, and nothing said so. `shop:provision` fills a new shop's
 * public folder with a COPY of `public/build` (`copyTree`), and the shop's
 * front controller points Laravel at that copy — `usePublicPath(SHOP_PUBLIC)`
 * in the shop system's `bootstrap/app.php`. So after a pull the shared codebase
 * has new markup and a new build, and every shop is serving new markup wearing
 * the stylesheet it was handed on the day it was created.
 *
 * ⚠️ **It fails silently, which is why it survived this long.** The shop reads
 * its manifest from its own folder, so the manifest and the files agree with
 * each other: nothing 404s, no exception is raised, and the page renders in
 * full and looks right. Only markup written since the copy has nothing to style
 * it. On the day it was found, that was four sparklines rendering as solid
 * black rectangles on a dashboard that was otherwise perfect — and every asset
 * change before them had been invisible to every shop, unnoticed, for months.
 *
 * The panel had all three of the pieces and none of the join. `Updater` cleared
 * every shop's compiled views after a pull and refreshed the panel's own
 * borrowed look, `ShopMigrator` ran a shop's migrations, and the shop system's
 * own `shop:update` copied the assets — a command the panel never called, and
 * still does not: `shop:update` also migrates, and Section 7 is deliberate that
 * a button labelled "update code" must not migrate other people's databases as
 * a side effect. So the panel does the assets itself, and only the assets.
 *
 * That is safe in the way clearing a cache is safe and migrating is not: no
 * database is opened, no data is touched, and `BuildFolder::replace` never
 * takes the old copy away until the new one is whole on the disk. Which is why
 * this happens automatically as part of updating the shop system, beside
 * `clearEveryShop`, rather than waiting behind a confirmation.
 */
class ShopAssets
{
    /** The shop system's compiled build — one source for every copy. */
    public function source(): string
    {
        return BuildFolder::shopSystem();
    }

    /**
     * Why the source cannot be handed out, if it cannot.
     *
     * @return list<string>
     */
    public function problems(?string $source = null): array
    {
        return BuildFolder::problems($source ?? $this->source());
    }

    /**
     * The shops whose copy is not the build the shared codebase is holding.
     *
     * Every customer, including suspended ones: a suspended shop's folder is
     * still on the disk and still comes back, and leaving it behind means the
     * day it is resumed is the day it breaks.
     *
     * A shop with no public folder on the disk is not "behind" — it is not
     * there, which is the Health screen's business and not this one's.
     *
     * @return list<Customer>
     */
    public function behind(?string $source = null): array
    {
        $source = $source ?? $this->source();

        if ($this->problems($source) !== []) {
            return [];
        }

        return Customer::all()
            ->filter(fn (Customer $c) => $this->folderOf($c) !== null
                && ! BuildFolder::matches($source, (string) $this->folderOf($c)))
            ->values()
            ->all();
    }

    /**
     * Hand the shared build to one shop.
     *
     * @return string the folder written
     *
     * @throws RuntimeException with the shop untouched
     */
    public function refresh(Customer $customer, ?string $source = null): string
    {
        $source = $source ?? $this->source();

        if (($problems = $this->problems($source)) !== []) {
            throw new RuntimeException(implode(' ', $problems).' Nothing was changed.');
        }

        $folder = $this->folderOf($customer);

        if ($folder === null) {
            throw new RuntimeException(sprintf(
                '%s has no public folder at [%s], so there is nowhere to put the stylesheet. Nothing was changed.',
                $customer->name,
                (string) $customer->public_path ?: 'no path recorded',
            ));
        }

        return BuildFolder::replace($source, $folder);
    }

    /**
     * Hand it to all of them, and let one shop's failure be its own.
     *
     * A shop whose disk is full must not stop the shop after it in the loop
     * from being fixed — the same reasoning as `ShopControls::clearEveryShop`,
     * and the reason both return what went wrong rather than throwing it.
     *
     * @return array{written: list<string>, stubborn: list<string>, source: string}
     */
    public function refreshEvery(?string $source = null): array
    {
        $source = $source ?? $this->source();

        if (($problems = $this->problems($source)) !== []) {
            throw new RuntimeException(implode(' ', $problems).' No shop was changed.');
        }

        $written = [];
        $stubborn = [];

        foreach ($this->behind($source) as $customer) {
            try {
                $written[] = $this->refresh($customer, $source);
            } catch (Throwable $e) {
                $stubborn[] = $customer->name.' — '.$e->getMessage();
            }
        }

        if ($written !== [] || $stubborn !== []) {
            Action::record('shops.assets_copied', null, [
                'source' => $source,
                'written' => count($written),
                'stubborn' => $stubborn,
            ]);
        }

        return ['written' => $written, 'stubborn' => $stubborn, 'source' => $source];
    }

    /**
     * Where this shop's build belongs, or null if its public folder is not there.
     *
     * `public_path` is what `shop:provision` was told and what the domain points
     * at, so it is read rather than rebuilt from the shop's name — on this
     * hosting a document root cannot leave public_html, so it is frequently not
     * beside `shop_home` at all.
     */
    private function folderOf(Customer $customer): ?string
    {
        $public = rtrim((string) $customer->public_path, '/');

        return $public !== '' && is_dir($public) ? $public.'/build' : null;
    }
}
