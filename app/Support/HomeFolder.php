<?php

namespace App\Support;

/**
 * The cPanel account's home folder.
 *
 * cPanel wants a document root RELATIVE to this, so not knowing it is the
 * doubled-path incident in DomainMaker's docblock waiting to happen again.
 * Three ways to learn it, in the order they deserve to be trusted:
 *
 *   1. PANEL_CPANEL_HOME. Somebody said so on purpose, so it wins.
 *   2. $HOME. Right in a shell, and EMPTY IN A WEB REQUEST — which is how
 *      every shop made from the panel's own screens came to fail on a panel
 *      where the same command worked perfectly over SSH.
 *   3. The account this process runs as. This is the one that answers in a
 *      web request, and it is the reason the setting above is optional.
 */
class HomeFolder
{
    /**
     * The home folder without its trailing slash, or an empty string when not
     * even the operating system will say.
     */
    public static function find(): string
    {
        $answers = [
            (string) config('panel.cpanel.home'),
            (string) getenv('HOME'),
            self::theAccountThisRunsAs(),
        ];

        foreach ($answers as $answer) {
            $answer = rtrim(trim($answer), '/');

            if ($answer !== '') {
                return $answer;
            }
        }

        return '';
    }

    /**
     * The passwd entry for whoever owns this process. Guarded by
     * function_exists because the posix extension is a separate package and
     * some hosts disable it.
     */
    private static function theAccountThisRunsAs(): string
    {
        if (! function_exists('posix_getpwuid') || ! function_exists('posix_getuid')) {
            return '';
        }

        $account = @posix_getpwuid(posix_getuid());

        return is_array($account) ? (string) ($account['dir'] ?? '') : '';
    }
}
