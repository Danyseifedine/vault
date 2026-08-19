<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Pins\IssuePin;
use App\Actions\Pins\SetPinStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pins\IssuePinRequest;
use App\Http\Requests\Pins\UpdatePinStatusRequest;
use App\Models\Organization;
use App\Models\Pin;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Reveal PINs, which only the organization owner may hand out or revoke.
 *
 * Both actions authorize internally against that narrower rule, so this
 * controller deliberately adds no check of its own - a second, looser one here
 * would be the weakest link.
 */
class PinController extends Controller
{
    public function store(
        IssuePinRequest $request,
        Organization $organization,
        IssuePin $issuePin,
    ): RedirectResponse {
        $holder = User::query()->findOrFail($request->integer('user_id'));

        $issuePin(
            $organization,
            $request->user(),
            $holder,
            $request->string('pin')->value(),
            $request->input('label'),
        );

        return back()->with('success', "PIN issued to {$holder->email}.");
    }

    public function update(
        UpdatePinStatusRequest $request,
        Organization $organization,
        Pin $pin,
        SetPinStatus $setStatus,
    ): RedirectResponse {
        $setStatus($pin, $request->user(), $request->status());

        return back()->with('success', 'PIN updated.');
    }
}
