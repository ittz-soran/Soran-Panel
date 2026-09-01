<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Setting up the phone that becomes the way back into the panel.
 *
 * Three steps, and the middle one is the point: a secret is generated, the
 * person proves their app is producing the right codes from it, and only then
 * is it written down as usable. A secret that was never proved is worse than
 * none — it reads as a way in, on a screen, and is not one.
 */
class AuthenticatorController extends Controller
{
    /** The setup screen: the square, the letters under it, and a box to prove it. */
    public function show(Request $request): View
    {
        $user = $request->user();

        // Held in the session until it is proved, so an abandoned setup leaves
        // nothing behind on the account.
        $secret = $request->session()->get('authenticator.secret') ?: Totp::secret();

        $request->session()->put('authenticator.secret', $secret);

        return view('profile.authenticator', [
            'user' => $user,
            'secret' => $secret,
            'readable' => Totp::readable($secret),
            'qr' => $this->qr(Totp::uri($secret, $user->email, config('app.name'))),
        ]);
    }

    /** The middle step. Nothing is written until a code comes back correct. */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $secret = $request->session()->get('authenticator.secret');

        if (! $secret) {
            return redirect()->route('authenticator.show')
                ->with('error', 'That took too long. Here is a new square to scan.');
        }

        if (! Totp::check($secret, $request->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right. Check the clock on the phone is correct, and try the next one.',
            ]);
        }

        $codes = User::newRecoveryCodes();

        $request->user()->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('authenticator.secret');

        // Shown once, on the next screen, and never again — they are only
        // useful because nothing else holds them in the clear.
        $request->session()->flash('recovery_codes', $codes);

        return redirect()->route('authenticator.show')
            ->with('success', 'The authenticator is on. Write these codes down somewhere safe.');
    }

    /** New codes, when the old list has been used up or seen by the wrong person. */
    public function regenerate(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasAuthenticator(), 404);

        $this->confirmPassword($request);

        $codes = User::newRecoveryCodes();

        $request->user()->forceFill(['two_factor_recovery_codes' => $codes])->save();
        $request->session()->flash('recovery_codes', $codes);

        return redirect()->route('authenticator.show')
            ->with('success', 'Here is a new set. The old ones no longer work.');
    }

    /**
     * Turning it off, which takes the current password.
     *
     * A screen somebody walked away from must not be a way to remove the only
     * thing standing between a stranger and every customer's install.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('authenticator.show')
            ->with('success', 'The authenticator is off. There is now no way back in without this password.');
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->string('password')->toString(), $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'That is not the current password.',
            ]);
        }
    }

    /**
     * The square, drawn on the server.
     *
     * As an SVG rather than a PNG so it needs no image extension, and inline
     * rather than from a URL so it works with the network down — which is when
     * somebody is most likely to be setting this up.
     */
    private function qr(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(240, 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($uri);
    }
}
