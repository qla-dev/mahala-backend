<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blocked;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BlockedController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->blockedIdsForUser((int) $request->user()->id),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'blocked_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $userId = (int) $request->user()->id;
        $blockedId = (int) $validated['blocked_id'];

        if ($userId === $blockedId) {
            throw ValidationException::withMessages([
                'blocked_id' => ['Ne možeš blokirati svoj račun.'],
            ]);
        }

        $blocked = Blocked::query()->firstOrCreate([
            'user_id' => $userId,
            'blocked_id' => $blockedId,
        ]);

        return response()->json([
            'data' => [
                'id' => $blocked->id,
                'user_id' => $blocked->user_id,
                'blocked_id' => $blocked->blocked_id,
            ],
            'blocked_ids' => $this->blockedIdsForUser($userId),
        ]);
    }

    private function blockedIdsForUser(int $userId): array
    {
        return Blocked::query()
            ->where('user_id', $userId)
            ->pluck('blocked_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
