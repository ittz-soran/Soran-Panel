<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * One call to cPanel's UAPI — PANEL_DOC Section 4.
 *
 * Section 4 measured `/usr/bin/uapi` answering on this account, and it is how
 * the panel does the two things a shared-hosting account will not let it do
 * directly: make a database, and point a domain at a folder.
 *
 * Extracted from CpanelDatabaseMaker when the domain maker needed the same
 * thing. The error handling is the part worth having in one place: UAPI reports
 * failure INSIDE a process that exited successfully, so a caller that only
 * checks the exit code believes every call worked.
 */
class Uapi
{
    private const TIMEOUT = 60;

    /**
     * @param  array<string, string|int>  $arguments
     * @return array<mixed>
     *
     * @throws RuntimeException with cPanel's own words, which are more use on a
     *                          screen than anything this could invent.
     */
    public function call(string $module, string $function, array $arguments): array
    {
        $command = [config('panel.cpanel.uapi', '/usr/bin/uapi'), '--output=json', $module, $function];

        foreach ($arguments as $name => $value) {
            $command[] = "{$name}={$value}";
        }

        /*
         * Without the panel's own environment. `uapi` reads the account it acts
         * for from the environment it is given, and Laravel exports the panel's
         * .env into every child process it starts.
         */
        $process = new Process($command, env: ShopEnvironment::withoutThePanel());
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'cPanel did not answer %s::%s in JSON. It said: %s',
                $module, $function,
                mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'nothing at all', 0, 300),
            ));
        }

        // `errors` is null when all is well and an array of sentences when it
        // is not — and a call that failed still exits 0.
        $errors = data_get($decoded, 'result.errors');

        if (! empty($errors)) {
            throw new RuntimeException(sprintf(
                'cPanel refused %s::%s — %s', $module, $function, implode(' ', (array) $errors),
            ));
        }

        if ((int) data_get($decoded, 'result.status', 0) !== 1) {
            throw new RuntimeException("cPanel did not carry out {$module}::{$function}, and gave no reason.");
        }

        return $decoded;
    }
}
