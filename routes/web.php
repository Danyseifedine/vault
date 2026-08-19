<?php

use App\Http\Controllers\Auth\CancelTwoFactorChallengeController;
use App\Http\Controllers\Auth\GoogleSignInController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\OnboardingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Organizations\ActivityController;
use App\Http\Controllers\Organizations\InvitationManagementController;
use App\Http\Controllers\Organizations\MemberController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\PinController;
use App\Http\Controllers\Organizations\SharedVaultController;
use App\Http\Controllers\Organizations\TagController;
use App\Http\Controllers\Personal\PersonalVaultController;
use App\Http\Controllers\Projects\EnvironmentController;
use App\Http\Controllers\Projects\GroupController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectSettingsController;
use App\Http\Controllers\Projects\RevealPolicyController;
use App\Http\Controllers\Variables\EnvDiffController;
use App\Http\Controllers\Variables\EnvFileController;
use App\Http\Controllers\Variables\RevealController;
use App\Http\Controllers\Variables\VariableController;
use App\Http\Controllers\Variables\VariableValueController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * There is no marketing page: this is an internal tool, and the root URL is
 * only ever a doorway. Signed in, it hands you to the dashboard; signed out,
 * to the sign-in screen.
 */
Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'))->name('home');

// The design-system showcase. It carries no real data, but nothing in an
// internal tool should answer to a guest, so it sits behind the same gate as
// the rest of the application.
Route::inertia('components', 'components/index')
    ->middleware(['auth', 'verified', 'onboarded'])
    ->name('components');

/*
 * Invitation links are public by necessity: the invitee has no account
 * session yet, and the token itself is the credential.
 */
/*
 * Google sign-in. Public because the visitor has no session yet - but it can
 * only ever match an account that already exists; it never creates one.
 */
Route::get('auth/google/redirect', [GoogleSignInController::class, 'redirect'])->name('google.redirect');
Route::get('auth/google/callback', [GoogleSignInController::class, 'callback'])->name('google.callback');

/*
 * The way back out of the two-factor challenge. Public for the same reason the
 * challenge itself is: the person using it has given a password but not a code,
 * and so has no session to log out of.
 */
Route::post('two-factor-challenge/cancel', CancelTwoFactorChallengeController::class)->name('two-factor.cancel');

Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

/*
 * Onboarding sits behind `auth` but deliberately NOT behind the onboarding
 * gate - it is the way out of that gate.
 */
Route::middleware('auth')->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('/', [OnboardingController::class, 'show'])->name('show');
    Route::post('password', [OnboardingController::class, 'storePassword'])->name('password');
    Route::post('two-factor', [OnboardingController::class, 'storeTwoFactor'])->name('two-factor');
    Route::post('profile', [OnboardingController::class, 'storeProfile'])->name('profile');
});

/*
 * The application proper. Nobody reaches any of this without a claimed
 * account, confirmed two-factor authentication, and a completed profile.
 *
 * scopeBindings() throughout: it is what stops a project being reached through
 * an organization it does not belong to, or a variable through the wrong
 * project - the URL cannot be used to cross a boundary.
 */
Route::middleware(['auth', 'verified', 'onboarded'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::post('orgs', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('orgs/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::patch('orgs/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::delete('orgs/{organization}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');

    /*
     * A person's own vault. No organization in the path and no policy: these
     * routes are scoped to the signed-in owner and nobody else, ever.
     */
    Route::prefix('personal')->name('personal.')->group(function () {
        Route::get('/', [PersonalVaultController::class, 'index'])->name('index');
        Route::post('secrets', [PersonalVaultController::class, 'storeSecret'])->name('secrets.store');
        Route::post('files', [PersonalVaultController::class, 'storeFile'])->name('files.store');
        Route::post('groups', [PersonalVaultController::class, 'storeGroup'])->name('groups.store');
        Route::patch('items/{item}', [PersonalVaultController::class, 'update'])->name('update');
        Route::post('items/{item}/reveal', [PersonalVaultController::class, 'reveal'])
            ->middleware('throttle:30,1')->name('reveal');
        Route::post('items/{item}/preview', [PersonalVaultController::class, 'preview'])
            ->middleware('throttle:30,1')->name('preview');
        Route::get('items/{item}/download', [PersonalVaultController::class, 'download'])
            ->middleware('throttle:30,1')->name('download');
        Route::delete('items/{item}', [PersonalVaultController::class, 'destroy'])->name('destroy');
        Route::delete('groups/{group}', [PersonalVaultController::class, 'destroyGroup'])->name('groups.destroy');
    });

    Route::prefix('orgs/{organization}')->scopeBindings()->group(function () {
        // People and their reach.
        Route::post('invitations', [MemberController::class, 'invite'])->name('members.invite');
        Route::delete('invitations/{invitation}', [InvitationManagementController::class, 'destroy'])
            ->name('invitations.destroy');
        Route::post('invitations/{invitation}/resend', [InvitationManagementController::class, 'resend'])
            ->name('invitations.resend');

        // The one write path for anyone's access: the payload IS the member's
        // grant set (optionally narrowed to one project). SetMemberGrants
        // authorizes and applies the lockout rule internally.
        Route::put('members/{member}/grants', [MemberController::class, 'grants'])
            ->withoutScopedBindings()
            ->name('members.grants');

        // PIN actions gate themselves on pins.manage; a second, looser check
        // here would be the weakest link.
        Route::post('pins', [PinController::class, 'store'])->name('pins.store');
        Route::patch('pins/{pin}', [PinController::class, 'update'])->name('pins.update');

        /*
         * The shared vault: credentials the organization holds in common.
         * Reveal answers JSON like the variable reveal does, and is throttled
         * at the edge as well as by the PIN attempt counter behind it.
         */
        Route::post('shared', [SharedVaultController::class, 'store'])->name('shared.store');
        Route::post('shared-groups', [SharedVaultController::class, 'storeGroup'])->name('shared.groups.store');
        Route::patch('shared/{shared}', [SharedVaultController::class, 'update'])
            ->withoutScopedBindings()
            ->name('shared.update');
        Route::delete('shared/{shared}', [SharedVaultController::class, 'destroy'])
            ->withoutScopedBindings()
            ->name('shared.destroy');
        Route::post('shared/{shared}/reveal', [SharedVaultController::class, 'reveal'])
            ->withoutScopedBindings()
            ->middleware('throttle:30,1')
            ->name('shared.reveal');

        /*
         * Files are read by streaming or by a bounded look, never as JSON - a
         * keystore is not UTF-8. Both POST because the PIN a shared read
         * always costs has nowhere to ride on a GET.
         */
        Route::post('shared/{shared}/download', [SharedVaultController::class, 'download'])
            ->withoutScopedBindings()
            ->middleware('throttle:30,1')
            ->name('shared.download');
        Route::post('shared/{shared}/preview', [SharedVaultController::class, 'preview'])
            ->withoutScopedBindings()
            ->middleware('throttle:30,1')
            ->name('shared.preview');

        Route::delete('shared-groups/{group}', [SharedVaultController::class, 'destroyGroup'])
            ->withoutScopedBindings()
            ->name('shared.groups.destroy');

        /*
         * The one route in the product that removes audit rows. It takes
         * `audit.wipe`, checked inside the action so the refusal is recorded,
         * and every wipe leaves a marker that no later wipe can remove.
         */
        Route::delete('activity', [ActivityController::class, 'destroy'])->name('activity.destroy');

        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::patch('tags/{tag}', [TagController::class, 'update'])
            ->withoutScopedBindings()
            ->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])
            ->withoutScopedBindings()
            ->name('tags.destroy');

        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

        Route::prefix('projects/{project}')->group(function () {
            Route::get('/', [ProjectController::class, 'show'])->name('projects.show');
            Route::patch('/', [ProjectController::class, 'update'])->name('projects.update');
            Route::delete('/', [ProjectController::class, 'destroy'])->name('projects.destroy');

            Route::put('settings', [ProjectSettingsController::class, 'update'])->name('projects.settings');

            // The project-sized version of the organization wipe: same
            // `audit.wipe` grant, narrower target.
            Route::delete('activity', [ActivityController::class, 'destroyForProject'])
                ->name('projects.activity.destroy');

            // Says which keys are missing or differ between two environments - // never what either value is.
            Route::get('diff', EnvDiffController::class)->name('projects.diff');

            Route::post('groups', [GroupController::class, 'store'])->name('groups.store');
            // Naming the ungrouped bucket - a literal segment, so it is matched
            // before the {group} wildcard below and never mistaken for a slug.
            Route::post('groups/ungrouped', [GroupController::class, 'adoptUngrouped'])
                ->name('groups.adopt-ungrouped');
            Route::patch('groups/{group}', [GroupController::class, 'update'])->name('groups.update');
            Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');

            Route::post('environments', [EnvironmentController::class, 'store'])->name('environments.store');

            Route::prefix('environments/{environment}')->group(function () {
                Route::patch('/', [EnvironmentController::class, 'update'])->name('environments.update');
                Route::delete('/', [EnvironmentController::class, 'destroy'])->name('environments.destroy');

                Route::put('reveal-policy', [RevealPolicyController::class, 'update'])->name('reveal-policy.update');

                // An export is a bulk reveal: same permission, same audit entry.
                Route::get('export', [EnvFileController::class, 'export'])->name('env.export');
                Route::post('import', [EnvFileController::class, 'import'])->name('env.import');
            });

            Route::post('variables', [VariableController::class, 'store'])->name('variables.store');

            Route::prefix('variables/{variable}')->group(function () {
                Route::patch('/', [VariableController::class, 'update'])->name('variables.update');
                Route::delete('/', [VariableController::class, 'destroy'])->name('variables.destroy');
                Route::put('tags', [TagController::class, 'sync'])->name('variables.tags');

                /*
                 * A variable and an environment are siblings, not parent and
                 * child - there is no relation to scope through, so these
                 * opt out and the controllers check containment themselves
                 * (see ScopesToProject).
                 */
                Route::prefix('environments/{environment}')->group(function () {
                    Route::put('value', [VariableValueController::class, 'update'])
                        ->withoutScopedBindings()
                        ->name('values.update');

                    // Metadata only: who changed it and when, never to what.
                    Route::get('history', [VariableValueController::class, 'history'])
                        ->withoutScopedBindings()
                        ->name('values.history');

                    Route::post('rollback/{version}', [VariableValueController::class, 'rollback'])
                        ->withoutScopedBindings()
                        ->name('values.rollback');

                    // The only route in the application that returns a secret.
                    Route::post('reveal', [RevealController::class, 'store'])
                        ->withoutScopedBindings()
                        ->name('values.reveal');
                });
            });
        });
    });
});

require __DIR__.'/settings.php';
