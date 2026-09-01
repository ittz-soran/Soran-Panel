<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Every Blade file compiles to PHP that parses.
 *
 * This exists because of a trap that cost an hour and would have cost a live
 * screen. Blade pulls raw `@php … @endphp` blocks out of a template BEFORE it
 * strips comments — so a `{{-- … --}}` comment that merely MENTIONS `@php`
 * opens a raw block that runs to the next `@endphp`, swallowing every directive
 * in between. The same goes for a comment containing an opening PHP tag.
 *
 * A Blade comment is not inert, and nothing says so. The failure is a 500 with
 * a parse error pointing at the end of the compiled file, nowhere near the
 * comment that caused it, and it only appears when that page is opened.
 *
 * Both mistakes were made here, in comments written to explain the first one.
 */
class ViewsCompileTest extends TestCase
{
    public function test_every_blade_template_compiles_to_php_that_parses(): void
    {
        $compiler = app('blade.compiler');
        $broken = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $compiled = $compiler->compileString($file->getContents());

            // php -l on the compiled output: the only judge that matters, and
            // the same one that runs when somebody opens the page.
            $temporary = tempnam(sys_get_temp_dir(), 'blade').'.php';
            file_put_contents($temporary, $compiled);

            $lint = new Process([PHP_BINARY, '-l', $temporary]);
            $lint->run();
            @unlink($temporary);

            if (! $lint->isSuccessful()) {
                $broken[$file->getRelativePathname()] = trim(explode("\n", $lint->getOutput())[0] ?? 'did not parse');
            }
        }

        $this->assertSame([], $broken, "These templates do not compile:\n".collect($broken)
            ->map(fn ($why, $file) => "  {$file} — {$why}")->implode("\n"));
    }

    /**
     * The specific trap, named.
     *
     * A comment mentioning a directive is a reasonable thing to want to write —
     * it is how you explain to the next person why the code is the way it is —
     * and it silently breaks the page. Better to be told which comment.
     */
    public function test_no_blade_comment_contains_a_php_directive_or_tag(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all('/\{\{--.*?--\}\}/s', $file->getContents(), $comments);

            foreach ($comments[0] as $comment) {
                if (preg_match('/@php\b|@endphp\b|<\?php/', $comment)) {
                    $offenders[] = $file->getRelativePathname();
                    break;
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "A Blade comment in %s mentions @php, @endphp or an opening PHP tag.\n".
            "Blade pulls raw PHP blocks out BEFORE it strips comments, so the comment opens one and\n".
            'swallows every directive until the next @endphp. Say "the block form" in words instead.',
            implode(', ', array_unique($offenders)),
        ));
    }
}
