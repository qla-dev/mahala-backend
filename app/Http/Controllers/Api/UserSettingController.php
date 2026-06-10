<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserSettingController extends Controller
{
    public function show(Request $request)
    {
        $settings = $this->settingsFor($request);
        Log::info('[MAHALA][settings] show', [
            'user_id' => $request->user()->id,
            'settings_id' => $settings->id,
        ]);

        return response()->json([
            'data' => $this->formatSettings($settings),
        ]);
    }

    public function update(Request $request)
    {
        Log::info('[MAHALA][settings] update raw request', [
            'user_id' => $request->user()->id,
            'payload' => $request->all(),
        ]);

        $validated = $request->validate([
            'notifications_app' => ['sometimes', 'boolean'],
            'notifications' => ['sometimes', 'boolean'],
            'notifications_app_location' => ['sometimes', 'boolean'],
            'notifications_app_comments' => ['sometimes', 'boolean'],
            'notifications_app_votes' => ['sometimes', 'boolean'],
            'notifications_location' => ['sometimes', 'boolean'],
            'notifications_new_mahala' => ['sometimes', 'boolean'],
            'notifications_startup' => ['sometimes', 'boolean'],
            'notifications_startup_mahalas' => ['sometimes', 'boolean'],
            'notifications_comments' => ['sometimes', 'boolean'],
            'notifications_votes' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'string', 'max:12'],
            'pro_status' => ['sometimes', 'integer', Rule::in([
                UserSetting::PRO_INACTIVE,
                UserSetting::PRO_MONTHLY,
                UserSetting::PRO_YEARLY,
            ])],
            'pro_started_at' => ['sometimes', 'nullable', 'date'],
            'pro_ends_at' => ['sometimes', 'nullable', 'date'],
        ]);

        Log::info('[MAHALA][settings] update request', [
            'user_id' => $request->user()->id,
            'payload' => $request->only([
                'notifications_app',
                'notifications',
                'notifications_app_location',
                'notifications_app_comments',
                'notifications_app_votes',
                'notifications_location',
                'notifications_new_mahala',
                'notifications_startup',
                'notifications_startup_mahalas',
                'notifications_comments',
                'notifications_votes',
                'locale',
                'pro_status',
                'pro_started_at',
                'pro_ends_at',
            ]),
            'validated' => $validated,
        ]);

        $settings = $this->settingsFor($request);
        Log::info('[MAHALA][settings] before save', [
            'user_id' => $request->user()->id,
            'settings_id' => $settings->id,
        ]);

        $validated = $this->normalizeWritableSettings($validated);
        $settings->fill($validated);

        if (array_key_exists('pro_status', $validated) && (int) $validated['pro_status'] === UserSetting::PRO_INACTIVE) {
            $settings->pro_started_at = null;
            $settings->pro_ends_at = null;
        }

        $settings->save();
        $settings = $settings->refresh();

        Log::info('[MAHALA][settings] after save', [
            'user_id' => $request->user()->id,
            'settings_id' => $settings->id,
        ]);

        return response()->json([
            'message' => 'Korisničke postavke su uspjesno ažurirane.',
            'data' => $this->formatSettings($settings),
        ]);
    }

    private function settingsFor(Request $request): UserSetting
    {
        return $request->user()->settings()->firstOrCreate([], [
            'notifications_comments' => true,
            'notifications_votes' => true,
            'notifications_location' => true,
            'notifications_startup_mahalas' => true,
            'locale' => 'bs',
            'pro_status' => UserSetting::PRO_INACTIVE,
        ]);
    }

    private function normalizeWritableSettings(array $validated): array
    {
        if (array_key_exists('notifications_app_location', $validated) && !array_key_exists('notifications_location', $validated)) {
            $validated['notifications_location'] = $validated['notifications_app_location'];
        }

        if (array_key_exists('notifications_new_mahala', $validated) && !array_key_exists('notifications_location', $validated)) {
            $validated['notifications_location'] = $validated['notifications_new_mahala'];
        }

        if (array_key_exists('notifications_startup', $validated) && !array_key_exists('notifications_startup_mahalas', $validated)) {
            $validated['notifications_startup_mahalas'] = $validated['notifications_startup'];
        }

        if (array_key_exists('notifications_app_comments', $validated) && !array_key_exists('notifications_comments', $validated)) {
            $validated['notifications_comments'] = $validated['notifications_app_comments'];
        }

        if (array_key_exists('notifications_app_votes', $validated) && !array_key_exists('notifications_votes', $validated)) {
            $validated['notifications_votes'] = $validated['notifications_app_votes'];
        }

        return collect($validated)
            ->only([
                'notifications_comments',
                'notifications_votes',
                'notifications_location',
                'notifications_startup_mahalas',
                'locale',
                'pro_status',
                'pro_started_at',
                'pro_ends_at',
            ])
            ->all();
    }

    private function formatSettings(UserSetting $settings): array
    {
        return [
            'id' => $settings->id,
            'user_id' => $settings->user_id,
            'notifications_app' => true,
            'notifications' => true,
            'notifications_app_location' => $settings->notifications_location,
            'notifications_app_comments' => $settings->notifications_comments,
            'notifications_app_votes' => $settings->notifications_votes,
            'notifications_location' => $settings->notifications_location,
            'notifications_startup_mahalas' => $settings->notifications_startup_mahalas,
            'notifications_comments' => $settings->notifications_comments,
            'notifications_votes' => $settings->notifications_votes,
            'locale' => $settings->locale,
            'pro_status' => $settings->pro_status,
            'pro_started_at' => $settings->pro_started_at,
            'pro_ends_at' => $settings->pro_ends_at,
            'created_at' => $settings->created_at,
            'updated_at' => $settings->updated_at,
        ];
    }
}
