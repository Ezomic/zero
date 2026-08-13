<?php

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Mail\SavedSearchCounts;
use App\Support\MailScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Naming a search so it stops having to be retyped (ZERO-120).
 */
class SavedSearchController extends Controller
{
    use InteractsWithCurrentUser;

    /** Enough for the rail to stay a rail. */
    protected const LIMIT = 20;

    public function index(): View
    {
        $user = $this->currentUser();
        $searches = $user->savedSearches;

        return view('inbox.saved-searches', [
            'searches' => $searches,
            'counts' => app(SavedSearchCounts::class)->forUser($user, $searches),
            'user' => $user,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->currentUser();

        if ($user->savedSearches()->count() >= self::LIMIT) {
            return back()->with('error', 'You already have '.self::LIMIT.' saved searches. Delete one first.');
        }

        $data = $this->stringKeyed($request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('saved_searches')->where('user_id', $user->id)],
            'query' => ['required', 'string', 'max:200'],
            'account' => ['nullable', 'integer'],
            'folder' => ['nullable', 'string', 'max:255'],
            'archived' => ['nullable', 'boolean'],
            'starred' => ['nullable', 'boolean'],
        ]));

        $search = $user->savedSearches()->create([
            'name' => $data['name'],
            'query' => $data['query'],
            // Only an account the user actually owns is stored. A borrowed id
            // would otherwise be saved and then filtered out on read, which
            // looks like a broken view rather than a rejected one.
            'mail_account_id' => $this->ownedAccountId(is_numeric($data['account'] ?? null) ? (int) $data['account'] : null),
            'folder' => is_string($data['folder'] ?? null) ? $data['folder'] : null,
            'archived' => (bool) ($data['archived'] ?? false),
            'starred' => (bool) ($data['starred'] ?? false),
            'position' => $this->nextPosition($user),
        ]);

        return redirect()
            ->route('inbox.index', MailScope::fromSavedSearch($search)->toQueryParams())
            ->with('status', "Saved as \"{$search->name}\".");
    }

    public function update(Request $request, SavedSearch $savedSearch): RedirectResponse
    {
        $this->authorizeOwnership($savedSearch);

        $data = $this->stringKeyed($request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('saved_searches')->where('user_id', $savedSearch->user_id)->ignore($savedSearch->id),
            ],
        ]));

        $savedSearch->update(['name' => $data['name']]);

        return back()->with('status', 'Renamed.');
    }

    /**
     * Swaps position with the neighbour in the given direction. A swap rather
     * than a rewrite of every row, so two views changing places cannot
     * renumber the rest.
     */
    public function move(Request $request, SavedSearch $savedSearch): RedirectResponse
    {
        $this->authorizeOwnership($savedSearch);

        $direction = $request->string('direction')->toString();
        abort_unless(in_array($direction, ['up', 'down'], true), 422);

        $neighbour = $this->currentUser()->savedSearches()
            ->where('id', '!=', $savedSearch->id)
            ->when(
                $direction === 'up',
                fn ($q) => $q->where('position', '<=', $savedSearch->position)->orderByDesc('position'),
                fn ($q) => $q->where('position', '>=', $savedSearch->position)->orderBy('position'),
            )
            ->first();

        if ($neighbour instanceof SavedSearch) {
            $position = $savedSearch->position;
            $savedSearch->update(['position' => $neighbour->position]);
            $neighbour->update(['position' => $position]);
        }

        return back();
    }

    public function destroy(SavedSearch $savedSearch): RedirectResponse
    {
        $this->authorizeOwnership($savedSearch);

        $name = $savedSearch->name;
        $savedSearch->delete();

        return back()->with('status', "Deleted \"{$name}\".");
    }

    protected function ownedAccountId(?int $accountId): ?int
    {
        if ($accountId === null) {
            return null;
        }

        $owned = $this->currentUser()->mailAccounts()->whereKey($accountId)->value('id');

        return is_numeric($owned) ? (int) $owned : null;
    }

    protected function nextPosition(User $user): int
    {
        $highest = $user->savedSearches()->max('position');

        return (is_numeric($highest) ? (int) $highest : 0) + 1;
    }

    protected function authorizeOwnership(SavedSearch $savedSearch): void
    {
        abort_unless($savedSearch->user_id === auth()->id(), 403);
    }
}
