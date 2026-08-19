<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\SharedVault\CreateSharedGroup;
use App\Actions\SharedVault\DeleteSharedGroup;
use App\Actions\SharedVault\DeleteSharedSecret;
use App\Actions\SharedVault\RevealSharedSecret;
use App\Actions\SharedVault\SaveSharedSecret;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\NamedResourceRequest;
use App\Http\Requests\SharedVault\RevealSharedSecretRequest;
use App\Http\Requests\SharedVault\SharedSecretRequest;
use App\Models\Organization;
use App\Models\SharedGroup;
use App\Models\SharedSecret;
use App\Services\Files\FilePreview;
use App\Services\Reveal\RevealOutcome;
use App\Services\Shared\SharedVaultGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The organization's shared vault: passwords, keys and files the team holds in
 * common. The list lives on the organization page; only the reveal answers
 * JSON, because a revealed value is shown once and must never ride a page prop.
 *
 * Every action authorizes inside itself through SharedVaultGuard, which is also
 * what records the refusals - this controller stays a translation layer. The
 * one thing it does decide is which endpoint suits which item: a typed secret
 * answers as JSON, a file streams. Containment is checked before that question
 * is answered, so "that id is a file" is never told to a stranger, exactly as
 * PersonalVaultController orders it.
 */
class SharedVaultController extends Controller
{
    public function __construct(private SharedVaultGuard $guard) {}

    private const STATUS = [
        RevealOutcome::DENIED => 403,
        RevealOutcome::LOCKED => 429,
        RevealOutcome::PIN_REQUIRED => 422,
        RevealOutcome::PIN_INCORRECT => 422,
        RevealOutcome::FILE_ITEM => 422,
    ];

    public function store(
        SharedSecretRequest $request,
        Organization $organization,
        SaveSharedSecret $save,
    ): RedirectResponse {
        $file = $request->file('file');

        $secret = $file === null
            ? $save->secret(
                $organization,
                $request->user(),
                $request->string('name')->value(),
                (string) $request->input('value'),
                $request->groupId(),
                $request->input('description'),
            )
            : $save->file(
                $organization,
                $request->user(),
                is_array($file) ? $file[0] : $file,
                $request->input('name'),
                $request->groupId(),
                $request->input('description'),
            );

        return back()->with('success', "{$secret->name} added to the shared vault.");
    }

    public function update(
        SharedSecretRequest $request,
        Organization $organization,
        SharedSecret $shared,
        SaveSharedSecret $save,
    ): RedirectResponse {
        $save->update(
            $shared,
            $organization,
            $request->user(),
            $request->string('name')->value(),
            $request->groupId(),
            $request->input('description'),
            $request->input('value'),
        );

        return back()->with('success', "{$shared->name} updated.");
    }

    public function destroy(
        Organization $organization,
        SharedSecret $shared,
        DeleteSharedSecret $delete,
    ): RedirectResponse {
        $name = $shared->name;

        $delete($shared, $organization, request()->user());

        return back()->with('success', "{$name} deleted.");
    }

    public function reveal(
        RevealSharedSecretRequest $request,
        Organization $organization,
        SharedSecret $shared,
        RevealSharedSecret $reveal,
    ): JsonResponse {
        // `secret()` rather than the action itself: a file's bytes cannot be
        // JSON, and this is the endpoint that answers in JSON.
        $outcome = $reveal->secret($shared, $organization, $request->user(), $request->input('pin'));

        if ($outcome->granted) {
            return response()
                ->json(['name' => $shared->name, 'value' => $outcome->value])
                ->header('Cache-Control', 'no-store');
        }

        return $this->refusal($outcome);
    }

    /**
     * Streams a shared file back, once the PIN is right.
     *
     * The PIN rides the POST body rather than a link, because reading a shared
     * item always costs one and a GET href has nowhere to carry it. The bytes
     * are decrypted in memory and streamed - the plaintext never touches disk,
     * and never has to survive JSON encoding.
     */
    public function download(
        RevealSharedSecretRequest $request,
        Organization $organization,
        SharedSecret $shared,
        RevealSharedSecret $reveal,
    ): StreamedResponse|JsonResponse {
        $this->guard->requireOwnItem($shared, $organization);
        abort_unless($shared->isFile(), 404);

        $outcome = $reveal($shared, $organization, $request->user(), $request->input('pin'));

        if (! $outcome->granted) {
            return $this->refusal($outcome);
        }

        $contents = (string) $outcome->value;

        return response()->streamDownload(
            fn () => print $contents,
            $shared->name,
            ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store'],
        );
    }

    /**
     * A bounded look inside a shared file.
     *
     * This decrypts, so it is a reveal in every sense - same PIN, same attempt
     * counter, same audit entry as a download. It returns at most a prefix,
     * and only ever as text or an image; anything else is reported rather than
     * dumped (FilePreview decides from the bytes, never the file name).
     */
    public function preview(
        RevealSharedSecretRequest $request,
        Organization $organization,
        SharedSecret $shared,
        RevealSharedSecret $reveal,
        FilePreview $preview,
    ): JsonResponse {
        $this->guard->requireOwnItem($shared, $organization);
        abort_unless($shared->isFile(), 404);

        $outcome = $reveal($shared, $organization, $request->user(), $request->input('pin'));

        if (! $outcome->granted) {
            return $this->refusal($outcome);
        }

        return response()
            ->json($preview((string) $outcome->value))
            ->header('Cache-Control', 'no-store');
    }

    public function storeGroup(
        NamedResourceRequest $request,
        Organization $organization,
        CreateSharedGroup $createGroup,
    ): RedirectResponse {
        $group = $createGroup($organization, $request->user(), $request->string('name')->value());

        return back()->with('success', "Group “{$group->name}” created.");
    }

    public function destroyGroup(
        Organization $organization,
        SharedGroup $group,
        DeleteSharedGroup $deleteGroup,
    ): RedirectResponse {
        $name = $group->name;

        $deleteGroup($group, $organization, request()->user());

        return back()->with('success', "Group “{$name}” removed. Everything in it was kept.");
    }

    /** The refusal shape all three read endpoints answer with. */
    private function refusal(RevealOutcome $outcome): JsonResponse
    {
        return response()->json([
            'reason' => $outcome->reason,
            'attempts_remaining' => $outcome->attemptsRemaining,
            'locked_until' => $outcome->lockedUntil?->toIso8601String(),
        ], self::STATUS[$outcome->reason] ?? 422);
    }
}
