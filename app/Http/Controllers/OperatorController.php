<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Who may sign in to the panel.
 *
 * The panel reaches into every customer's install, so an account here is worth
 * more than an account in any one shop — PANEL_DOC Section 1, rule 2, is that
 * anything reaching into a customer leaves a record with a name on it, and that
 * only means something if the names are looked after.
 *
 * Three rules run through all of this, and each exists because of a way a
 * person gets locked out of the only panel there is:
 *
 *   1. Nobody can remove or deactivate themselves. There is no /register and
 *      no forgotten-password email, so the last admin locking themselves out is
 *      not an inconvenience, it is a database edit at three in the morning.
 *   2. The last active admin cannot be removed, deactivated, or demoted.
 *   3. Everything here is logged, including who did it.
 */
class OperatorController extends Controller
{
    public function index(): View
    {
        return view('operators.index', [
            'operators' => User::withTrashed()->orderByDesc('is_active')->orderBy('name')->get(),
            'admins' => $this->activeAdmins(),
        ]);
    }

    public function create(): View
    {
        return view('operators.form', ['operator' => new User(['role' => User::ROLE_ADMIN, 'is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $fields = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STAFF])],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $operator = User::create([...$fields, 'is_active' => true]);

        Action::record('operator.added', null, [
            'name' => $operator->name, 'email' => $operator->email, 'role' => $operator->role,
        ]);

        return redirect()->route('operators.index')->with(
            'success',
            "{$operator->name} can now sign in. Tell them to set up the authenticator at once — it is the only way back in.",
        );
    }

    public function edit(User $operator): View
    {
        return view('operators.form', ['operator' => $operator]);
    }

    public function update(Request $request, User $operator): RedirectResponse
    {
        $fields = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($operator)->withoutTrashed()],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STAFF])],
            'password' => ['nullable', 'confirmed', Password::min(12)],
        ]);

        if ($problem = $this->wouldStrandThePanel($operator, $fields['role'])) {
            return back()->withInput()->with('warning', $problem);
        }

        $was = $operator->only(['name', 'email', 'role']);

        $operator->fill(Arr::except($fields, 'password'));

        if (filled($fields['password'] ?? null)) {
            $operator->password = $fields['password'];
        }

        $changed = $operator->getDirty();
        $operator->save();

        if ($changed !== [] || filled($fields['password'] ?? null)) {
            Action::record('operator.changed', null, [
                'who' => $operator->email,
                'from' => array_intersect_key($was, $changed),
                'to' => Arr::except($changed, ['password', 'updated_at']),
                'password' => filled($fields['password'] ?? null) ? 'set' : 'unchanged',
            ]);
        }

        return redirect()->route('operators.index')->with('success', "{$operator->name} updated.");
    }

    /** Off, not gone: their name stays on everything they did. */
    public function deactivate(Request $request, User $operator): RedirectResponse
    {
        $wantsActive = $request->boolean('active');

        if (! $wantsActive && ($problem = $this->wouldStrandThePanel($operator))) {
            return back()->with('warning', $problem);
        }

        $operator->update(['is_active' => $wantsActive]);

        Action::record($wantsActive ? 'operator.enabled' : 'operator.disabled', null, ['who' => $operator->email]);

        return back()->with('success', $wantsActive
            ? "{$operator->name} can sign in again."
            : "{$operator->name} can no longer sign in. Everything they did is still on record.");
    }

    /**
     * A lost phone, and no recovery codes left.
     *
     * The one thing an operator cannot fix for themselves: without the
     * authenticator and without a code, there is no way back in at all. Somebody
     * else with an account has to clear it, and it is logged, because clearing
     * somebody's second factor is exactly what an attacker would want to do.
     */
    public function resetAuthenticator(User $operator): RedirectResponse
    {
        $operator->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Action::record('operator.authenticator_reset', null, ['who' => $operator->email]);

        return back()->with('warning',
            "{$operator->name}'s authenticator is cleared. They sign in with their password and set it up again — "
            .'until they do, their password is the only thing in the way.');
    }

    /** Soft deleted: `actions` keeps their name on what they did. */
    public function destroy(User $operator): RedirectResponse
    {
        if ($problem = $this->wouldStrandThePanel($operator)) {
            return back()->with('warning', $problem);
        }

        $operator->delete();

        Action::record('operator.removed', null, ['who' => $operator->email, 'name' => $operator->name]);

        return redirect()->route('operators.index')->with('success',
            "{$operator->name} removed. What they did is still on record.");
    }

    public function restore(int $operator): RedirectResponse
    {
        $found = User::withTrashed()->findOrFail($operator);
        $found->restore();

        Action::record('operator.restored', null, ['who' => $found->email]);

        return back()->with('success', "{$found->name} is back.");
    }

    /**
     * Why this may not happen — or null if it may.
     *
     * There is no /register and no forgotten-password email, so an empty panel
     * is not an inconvenience: it is a database edit by hand, on a server, at
     * whatever hour it is discovered.
     */
    private function wouldStrandThePanel(User $operator, ?string $newRole = null): ?string
    {
        if ($operator->is(auth()->user()) && $newRole === null) {
            return 'You cannot remove or switch off your own account. Ask another operator to do it.';
        }

        if ($operator->is(auth()->user()) && $newRole === User::ROLE_STAFF) {
            return 'You cannot take away your own admin role. Ask another operator to do it.';
        }

        $stillAdmin = $newRole === null ? false : $newRole === User::ROLE_ADMIN;

        if ($operator->isAdmin() && ! $stillAdmin && $this->activeAdmins() <= 1) {
            return 'This is the only admin left. Add another one first, or nobody can get into the panel at all.';
        }

        return null;
    }

    private function activeAdmins(): int
    {
        return User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count();
    }
}
