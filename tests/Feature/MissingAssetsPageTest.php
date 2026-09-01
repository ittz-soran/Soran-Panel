<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\View\ViewException;
use Tests\TestCase;

/**
 * What the panel says when its borrowed assets are not there.
 *
 * PANEL_DOC Section 10 has the panel reuse the shop system's compiled build/,
 * copied in at deploy time. Until it is, Laravel throws "Vite manifest not
 * found" — true, and useless here. Everywhere else that message means "run
 * `npm run build`"; in the panel it cannot, because there is no npm, no
 * package.json and no stylesheet of its own. Somebody reading Laravel's own
 * message goes looking for a build script that was removed on purpose.
 *
 * The wrapping is the part worth a test. Vite reads the manifest while a Blade
 * view is rendering, and Blade rethrows anything that happens there wrapped in
 * a ViewException — so a handler type-hinted on the Vite exception matches
 * nothing at all, which is exactly what the first version of this did. It sat
 * in bootstrap/app.php looking correct and never once fired.
 */
class MissingAssetsPageTest extends TestCase
{
    private function render(\Throwable $e): string
    {
        $response = app(ExceptionHandler::class)->render(request(), $e);

        return $response->getContent();
    }

    public function test_it_explains_the_missing_assets_when_blade_has_wrapped_the_failure(): void
    {
        // Exactly how it arrives in real life.
        $wrapped = new ViewException(
            'Vite manifest not found at: /app/public/build/manifest.json',
            0,
            1,
            'view.blade.php',
            1,
            new ViteManifestNotFoundException('Vite manifest not found at: /app/public/build/manifest.json'),
        );

        $content = $this->render($wrapped);

        $this->assertStringContainsString('The panel has no assets yet', $content);
        $this->assertStringContainsString('npm run build', $content);
        $this->assertStringContainsString('SystemManagment', $content);
    }

    public function test_it_explains_it_when_the_failure_arrives_unwrapped_too(): void
    {
        $content = $this->render(
            new ViteManifestNotFoundException('Vite manifest not found at: /app/public/build/manifest.json'),
        );

        $this->assertStringContainsString('The panel has no assets yet', $content);
    }

    /**
     * It prints this install's real path, exactly as PHP reports it.
     *
     * The first version rewrote the separators to look Windows-ish, which on
     * Linux turned /home/user/... into \home\user\... — a path to nowhere, in
     * the one place somebody is copying and pasting rather than reading.
     */
    public function test_it_names_this_installs_own_build_folder_unrewritten(): void
    {
        $content = $this->render(new ViteManifestNotFoundException('Vite manifest not found'));

        $this->assertStringContainsString(public_path('build'), $content);
    }

    /**
     * Everything else is left to Laravel.
     *
     * Asserted on the title tag rather than the heading: Laravel's debug page
     * prints the source of the files in the stack trace, and this file is one
     * of them — so an assertion against the heading text found its own source
     * code and failed.
     */
    public function test_it_does_not_swallow_unrelated_failures(): void
    {
        $content = $this->render(new \RuntimeException('something else went wrong'));

        $this->assertStringNotContainsString('<title>The panel has no assets yet</title>', $content);
    }
}
