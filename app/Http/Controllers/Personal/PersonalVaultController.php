<?php

namespace App\Http\Controllers\Personal;

use App\Actions\PersonalVault\CreatePersonalGroup;
use App\Actions\PersonalVault\CreatePersonalSecret;
use App\Actions\PersonalVault\DeletePersonalItem;
use App\Actions\PersonalVault\RevealPersonalSecret;
use App\Actions\PersonalVault\StorePersonalFile;
use App\Actions\PersonalVault\UpdatePersonalItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Personal\StorePersonalFileRequest;
use App\Http\Requests\Personal\StorePersonalGroupRequest;
use App\Http\Requests\Personal\StorePersonalSecretRequest;
use App\Http\Requests\Personal\UpdatePersonalItemRequest;
use App\Models\AuditLog;
use App\Models\PersonalGroup;
use App\Models\PersonalSecret;
use App\Models\User;
use App\Services\Files\FilePreview;
use App\Services\Personal\PersonalGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One person's private vault.
 *
 * Every query here is scoped by `ownedBy` and every action re-checks ownership.
 * There is no administrative route into this data, by design - see PersonalGuard.
 */
class PersonalVaultController extends Controller
{
    /**
     * The owner's own vault.
     *
     * Metadata only: a listing never carries a value, not even a personal one
     *. Everything is scoped by `ownedBy`, which is the whole
     * security model here - there is no administrative view of this data.
     */
    public function index(Request $request): Response
    {
        $owner = $request->user();

        return Inertia::render('personal/index', [
            'groups' => PersonalGroup::query()
                ->ownedBy($owner)
                ->orderBy('position')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'items' => PersonalSecret::query()
                ->ownedBy($owner)
                ->latest('id')
                ->get(['id', 'personal_group_id', 'type', 'name', 'description', 'updated_at'])
                ->map(fn (PersonalSecret $item) => [
                    'id' => $item->id,
                    'groupId' => $item->personal_group_id,
                    'type' => $item->type->value,
                    'name' => $item->name,
                    'description' => $item->description,
                    'updatedAt' => $item->updated_at?->diffForHumans(),
                ])
                ->all(),
            'activity' => $this->activity($owner),
        ]);
    }

    /**
     * The private personal log, readable at last.
     *
     * A trail that only the owner can query - this is that query: entries with
     * no organization and no project, caused by the owner. Event names only; a
     * value never reaches the log to begin with.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(User $owner): array
    {
        return AuditLog::query()
            ->whereNull('organization_id')
            ->whereNull('project_id')
            ->where('causer_id', $owner->id)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $entry) => [
                'action' => str_replace(['.', '-'], [' ', ' '], $entry->event),
                'name' => $entry->properties['name'] ?? null,
                'failed' => ($entry->properties['failed'] ?? false) === true,
                'when' => $entry->created_at->diffForHumans(short: true),
            ])
            ->all();
    }

    /** The optional group, as an id or nothing - repeated by three endpoints. */
    private function groupId(Request $request): ?int
    {
        return $request->input('personal_group_id') === null
            ? null
            : $request->integer('personal_group_id');
    }

    public function storeSecret(
        StorePersonalSecretRequest $request,
        CreatePersonalSecret $createSecret,
    ): RedirectResponse {
        $createSecret(
            $request->user(),
            $request->string('name')->value(),
            $request->string('value')->value(),
            $this->groupId($request),
            $request->input('description'),
        );

        return back()->with('success', 'Saved to your vault.');
    }

    public function storeFile(
        StorePersonalFileRequest $request,
        StorePersonalFile $storeFile,
    ): RedirectResponse {
        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        $storeFile(
            $request->user(),
            $file,
            $request->input('name'),
            $this->groupId($request),
            $request->input('description'),
        );

        return back()->with('success', 'File stored, encrypted.');
    }

    public function storeGroup(
        StorePersonalGroupRequest $request,
        CreatePersonalGroup $createGroup,
    ): RedirectResponse {
        $createGroup($request->user(), $request->string('name')->value(), $request->integer('position'));

        return back()->with('success', 'Group created.');
    }

    public function update(
        UpdatePersonalItemRequest $request,
        PersonalSecret $item,
        UpdatePersonalItem $updateItem,
    ): RedirectResponse {
        $updateItem(
            $item,
            $request->user(),
            $request->string('name')->value(),
            $this->groupId($request),
            $request->input('description'),
            // Absent or blank means "keep the stored one" - the Action decides,
            // so the rule lives in exactly one place.
            $request->input('value'),
        );

        return back()->with('success', 'Updated.');
    }

    /** Returns the value once, as JSON - never as a page prop. */
    public function reveal(
        Request $request,
        PersonalSecret $item,
        RevealPersonalSecret $reveal,
    ): JsonResponse {
        return response()->json([
            'name' => $item->name,
            'value' => $reveal($item, $request->user()),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * A bounded look inside a stored file.
     *
     * This decrypts, so it goes through the same reveal path a download does
     * and is audited identically - the plaintext left the server either way.
     * It returns at most a prefix, and only ever as text or an image; anything
     * else is reported rather than dumped.
     */
    public function preview(
        Request $request,
        PersonalSecret $item,
        RevealPersonalSecret $reveal,
        FilePreview $preview,
        PersonalGuard $guard,
    ): JsonResponse {
        // Ownership FIRST: answering "is it a file" before "is it yours" would
        // hand an id-enumerating stranger a type oracle, and their probes at
        // non-file ids would leave no intrusion entry.
        $guard->requireOwner($item, $request->user());
        abort_unless($item->isFile(), 404);

        return response()
            ->json($preview($reveal($item, $request->user())))
            ->header('Cache-Control', 'no-store');
    }

    public function download(
        Request $request,
        PersonalSecret $item,
        RevealPersonalSecret $reveal,
        PersonalGuard $guard,
    ): StreamedResponse {
        // Same ordering as preview, for the same reason.
        $guard->requireOwner($item, $request->user());
        abort_unless($item->isFile(), 404);

        // Decrypted in memory and streamed - the plaintext never touches disk.
        $contents = $reveal($item, $request->user());

        return response()->streamDownload(
            fn () => print $contents,
            $item->name,
            ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store'],
        );
    }

    public function destroy(
        Request $request,
        PersonalSecret $item,
        DeletePersonalItem $delete,
    ): RedirectResponse {
        $delete($item, $request->user());

        return back()->with('success', 'Removed from your vault.');
    }

    public function destroyGroup(
        Request $request,
        PersonalGroup $group,
        DeletePersonalItem $delete,
    ): RedirectResponse {
        $delete->group($group, $request->user());

        return back()->with('success', 'Group removed.');
    }
}
