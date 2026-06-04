<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushTokenController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'provider' => ['sometimes', 'string', Rule::in(['expo'])],
            'platform' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notification_channel_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'sound' => ['sometimes', 'nullable', 'string', 'max:128'],
            'preferences' => ['sometimes', 'array'],
            'preferences.app' => ['sometimes', 'boolean'],
            'preferences.comments' => ['sometimes', 'boolean'],
            'preferences.votes' => ['sometimes', 'boolean'],
            'preferences.location' => ['sometimes', 'boolean'],
        ]);

        $token = PushToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'provider' => $validated['provider'] ?? 'expo',
                'platform' => $validated['platform'] ?? null,
                'notification_channel_id' => $validated['notification_channel_id'] ?? null,
                'sound' => $validated['sound'] ?? null,
                'preferences' => $validated['preferences'] ?? [],
                'disabled_at' => null,
                'last_error' => null,
                'last_used_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Push token je uspjesno sacuvan.',
            'data' => [
                'id' => $token->id,
                'provider' => $token->provider,
                'platform' => $token->platform,
                'last_used_at' => $token->last_used_at,
            ],
        ]);
    }
}
