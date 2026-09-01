<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The panel draws no icon the shop system's stylesheet does not carry.
 *
 * PANEL_DOC Section 10 has the panel reuse the shop system's compiled build/
 * rather than build a stylesheet of its own. What Section 10 does not say — and
 * what this test exists to hold — is that the shop system SUBSETS its icons
 * (tools/subset-icons.py: "only the icons this shop draws"). Seventy-four of
 * Bootstrap Icons' two thousand survive into the CSS the panel borrows.
 *
 * So an icon that is perfectly real, and works in any other Bootstrap project,
 * draws as nothing here. It was found exactly that way: the first sidebar had a
 * bi-heart-pulse beside Health and a bi-sliders2 as the panel's own mark, and
 * both were simply absent on screen — no error, no empty box, no console
 * warning. Nothing but a gap.
 *
 * The test is skipped when public/build is not there, because build/ is copied
 * in at deploy time and is not committed. That is the honest shape: this is a
 * deploy-time check, and it runs wherever the assets the panel will actually
 * serve are present.
 */
class BorrowedStylesheetTest extends TestCase
{
    public function test_every_icon_the_panel_draws_exists_in_the_stylesheet_it_borrows(): void
    {
        $css = $this->borrowedCss();

        $available = [];
        preg_match_all('/\.(bi-[a-z0-9-]+)/', $css, $matches);
        $available = array_flip($matches[1]);

        $missing = [];

        foreach ($this->iconsThePanelDraws() as $icon => $files) {
            if (! isset($available[$icon])) {
                $missing[$icon] = $files;
            }
        }

        $this->assertSame([], $missing, $missing === [] ? '' : sprintf(
            "These icons are not in the shop system's subset, so they draw as nothing:\n%s\n".
            "Either pick one of the %d icons the subset carries, or add them to the shop\n".
            'system\'s tools/subset-icons.py and rebuild its assets.',
            collect($missing)->map(fn ($files, $icon) => "  {$icon} — ".implode(', ', $files))->implode("\n"),
            count($available),
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    private function iconsThePanelDraws(): array
    {
        $roots = [resource_path('views'), app_path()];
        $found = [];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                preg_match_all('/\bbi-[a-z0-9-]+/', (string) file_get_contents($file->getPathname()), $matches);

                foreach (array_unique($matches[0]) as $icon) {
                    $found[$icon][] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        ksort($found);

        return $found;
    }

    private function borrowedCss(): string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->markTestSkipped(
                'public/build is not present. It is the shop system\'s compiled build/, '
                .'copied in at deploy time (Section 10), so this check runs where the assets do.'
            );
        }

        $entries = json_decode((string) file_get_contents($manifest), true);
        $css = '';

        foreach ($entries as $entry) {
            foreach ($entry['css'] ?? [] as $file) {
                $css .= (string) file_get_contents(public_path('build/'.$file));
            }

            if (str_ends_with($entry['file'] ?? '', '.css')) {
                $css .= (string) file_get_contents(public_path('build/'.$entry['file']));
            }
        }

        $this->assertNotSame('', $css, 'The borrowed build/ carries no stylesheet at all.');

        return $css;
    }

    /**
     * The names the layouts ask @vite for must be keys in the borrowed manifest.
     *
     * This is the check the base TestCase's withoutVite() would otherwise hide,
     * and it is the one that breaks quietly: the panel's shells ask for
     * `resources/scss/app.scss` and `resources/js/app.js` because those are the
     * shop system's Vite entry names, not because the panel has such files —
     * it has neither, and no npm at all. If the shop system ever renames an
     * entry, the copied manifest stops answering and every page throws.
     */
    public function test_the_entry_names_the_layouts_ask_for_are_in_the_borrowed_manifest(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->markTestSkipped(
                'public/build is not present. It is the shop system\'s compiled build/, '
                .'copied in at deploy time (Section 10), so this check runs where the assets do.'
            );
        }

        $entries = json_decode((string) file_get_contents($manifest), true);
        $asked = $this->entriesTheLayoutsAskFor();

        $this->assertNotSame([], $asked, 'no @vite call was found in any layout at all');

        foreach ($asked as $entry => $file) {
            // Only the real entry points in the message. The manifest also keys
            // every bundled font, and a hundred .woff lines buries the answer.
            $available = array_keys(array_filter(
                $entries,
                fn ($details) => ! empty($details['isEntry']),
            ));

            $this->assertArrayHasKey($entry, $entries, sprintf(
                "%s asks @vite for [%s], which the borrowed manifest does not have.\n".
                "The entries it does have: %s\n".
                'Either the shop system renamed an entry, or build/ is stale.',
                $file, $entry, implode(', ', $available),
            ));
        }
    }

    /**
     * @return array<string, string> entry name => the view that asks for it
     */
    private function entriesTheLayoutsAskFor(): array
    {
        $asked = [];

        // Not glob(): PHP's ** is not recursive, so a layout that moved up or
        // down a folder would silently stop being checked.
        $views = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($views as $view) {
            if (! $view->isFile() || ! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            $view = $view->getPathname();

            preg_match_all(
                '/@vite\(\s*\[(.*?)\]/s',
                (string) file_get_contents($view),
                $calls,
            );

            foreach ($calls[1] as $arguments) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $arguments, $names);

                foreach ($names[1] as $entry) {
                    $asked[$entry] = str_replace(base_path().'/', '', $view);
                }
            }
        }

        return $asked;
    }
}
