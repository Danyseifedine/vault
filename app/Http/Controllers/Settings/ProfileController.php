<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Organization;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'emailVerified' => $user->email_verified_at !== null,
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * The name, and only the name. An email address is this account's identity:
     * every invitation, grant and audit row was written against it, so letting
     * it move here would quietly rewrite who those records were about. Changing
     * it is an administrative act, not a settings toggle.
     */
    public function update(ProfileUpdateRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $request->user()->fill(['name' => $request->validated('name')])->save();

        $audit->record(
            'account.renamed',
            properties: ['name' => $request->validated('name')],
            scope: AuditScope::none(),
            causer: $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();

        // Organizations point at their creator with a restricting foreign key,
        // so this would otherwise die in the database and read as a broken
        // application rather than as something left to do.
        $owned = Organization::query()->where('created_by', $user->id)->pluck('name');

        if ($owned->isNotEmpty()) {
            throw ValidationException::withMessages([
                'password' => 'Delete or hand over '.$owned->join(', ', ' and ').' first - an organization cannot outlive the account that created it.',
            ]);
        }

        // Recorded BEFORE the row goes: the entry must outlive the account,
        // and the log has no foreign key to users precisely so it can.
        $audit->record(
            'account.deleted',
            properties: ['email' => $user->email],
            scope: AuditScope::none(),
            causer: $user,
        );

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
