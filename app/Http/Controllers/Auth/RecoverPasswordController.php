<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Getting back into the panel without the password and without the post.
 *
 * The shop system's flow, for the shop system's reason: MAIL_MAILER is `log` on
 * this hosting, so Laravel's forgotten-password link has never left the
 * building. Here it matters more than it does in a shop. A shopkeeper locked
 * out of one shop can telephone Soran; Soran locked out of the panel has nobody
 * to telephone, and behind it is every customer's install.
 *
 * So: the email address, the six digits off the enrolled phone, and a new
 * password. Nothing has to be delivered anywhere.
 *
 * Six digits is a million guesses, so it is rate limited hard — per account and
 * per machine together. Without that this is a doorway rather than a door.
 */
class RecoverPasswordController extends Controller
{
    /** Five tries, then a minute of nothing. A million guesses is a long time at that rate. */
    private const TRIES = 5;

    private const LOCKOUT = 60;

    public function show(): View
    {
        return view('auth.recover');
    }

    /**
     * Everything in one step, because somebody standing at a locked screen
     * wants a way in rather than a wizard.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->refuseIfTooManyTries($request, $data['email']);

        $user = User::where('email', $data['email'])->first();

        // One message for every way of being wrong, so this cannot be used to
        // ask which addresses have an account or which have an authenticator.
        $how = $user && $user->hasAuthenticator() ? $this->accepts($user, $data['code']) : null;

        if ($how === null) {
            RateLimiter::hit($this->key($request, $data['email']), self::LOCKOUT);

            throw ValidationException::withMessages([
                'code' => 'That did not work. Check the email address and the six digits the app is showing right now — or use one of the recovery codes instead.',
            ]);
        }

        RateLimiter::clear($this->key($request, $data['email']));

        // Every session this account had open, ended. A password reset that
        // leaves the old sessions signed in has not locked anybody out.
        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => null,
        ])->save();

        /*
         * Section 5: what the panel did, and who told it to.
         *
         * This waited on the `actions` table, which arrived with the schema and
         * then went unnoticed for a week — so the one way into the panel that
         * does not need the password left no trace at all. Nothing sensitive is
         * kept: that it happened, to whom, from which address, and when.
         *
         * `by:` because there is no session here. Whoever did this proved who
         * they were with the six digits and is now being sent to the sign-in
         * screen; `auth()->id()` is null, and null is not a name.
         */
        Action::record('operator.password_recovered', detail: ['how' => $how], by: $user);

        return redirect()->route('login')
            ->with('success', 'The password is changed. Sign in with the new one.');
    }

    /** The phone, or one of the eight codes written down when it was set up. */
    /**
     * Which of the two was used, or null for neither.
     *
     * It returned a bare true/false until the log needed it, and the difference
     * is worth recording: a recovery code is one of a finite set, and spending
     * one is a thing to notice — both because they run out, and because it is
     * what somebody does when they no longer have the phone.
     */
    private function accepts(User $user, string $code): ?string
    {
        if (Totp::check($user->two_factor_secret, $code)) {
            return 'the six digits from the authenticator';
        }

        return $user->spendRecoveryCode($code) ? 'a recovery code, now spent' : null;
    }

    private function refuseIfTooManyTries(Request $request, string $email): void
    {
        if (! RateLimiter::tooManyAttempts($this->key($request, $email), self::TRIES)) {
            return;
        }

        throw ValidationException::withMessages([
            'code' => 'Too many tries. Wait '.RateLimiter::availableIn($this->key($request, $email)).' seconds and start again.',
        ]);
    }

    /**
     * Counted per account and per machine together.
     *
     * Per account alone lets anybody lock Soran out by guessing badly on
     * purpose; per machine alone lets a patient attacker work through every
     * account from one place. Both, and neither trick works.
     */
    private function key(Request $request, string $email): string
    {
        return 'recover|'.mb_strtolower($email).'|'.$request->ip();
    }
}
