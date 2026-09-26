<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\DeleteAccount;
use App\Enums\AppLocale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'locales' => collect(AppLocale::cases())->map(fn (AppLocale $locale) => ['value' => $locale->value, 'label' => $locale->label()])->all(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        $user = $request->user();

        // No password to type: the person must have confirmed it is them
        // recently (signing in with Google counts). The confirmation brings
        // them back here to delete again.
        $confirmedAgo = now()->getTimestamp() - $request->session()->get('auth.password_confirmed_at', 0);
        if (! $user->hasPassword() && $confirmedAgo > config('auth.password_timeout', 10800)) {
            return redirect()->guest(route('password.confirm'));
        }

        $deleteAccount->handle($user);

        Auth::guard('web')->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
