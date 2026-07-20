<?php

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    use InteractsWithCurrentUser;

    /**
     * Used by the compose page's To/Cc autocomplete dropdown.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        $contacts = $this->currentUser()->contacts()
            ->where(function ($query) use ($q) {
                $query->where('email', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%");
            })
            ->orderByDesc('last_seen_at')
            ->limit(8)
            ->get(['email', 'name']);

        return response()->json($contacts);
    }
}
