<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserSettingController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'data' => $this->formatSettings($this->settingsFor($request)),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'notifications_app' => ['sometimes', 'boolean'],
            'notifications' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'string', 'max:12'],
            'pro_status' => ['sometimes', 'integer', Rule::in([
                UserSetting::PRO_INACTIVE,
                UserSetting::PRO_MONTHLY,
                UserSetting::PRO_YEARLY,
            ])],
            'pro_started_at' => ['sometimes', 'nullable', 'date'],
            'pro_ends_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $settings = $this->settingsFor($request);
        $settings->fill($validated);

        if (array_key_exists('pro_status', $validated) && (int) $validated['pro_status'] === UserSetting::PRO_INACTIVE) {
            $settings->pro_started_at = null;
            $settings->pro_ends_at = null;
        }

        $settings->save();

        return response()->json([
            'message' => 'User settings updated successfully.',
            'data' => $this->formatSettings($settings->refresh()),
        ]);
    }

    private function settingsFor(Request $request): UserSetting
    {
        return $request->user()->settings()->firstOrCreate([], [
            'notifications_app' => true,
            'notifications' => true,
            'locale' => 'bs',
            'pro_status' => UserSetting::PRO_INACTIVE,
        ]);
    }

    private function formatSettings(UserSetting $settings): array
    {
        return [
            'id' => $settings->id,
            'user_id' => $settings->user_id,
            'notifications_app' => $settings->notifications_app,
            'notifications' => $settings->notifications,
            'locale' => $settings->locale,
            'pro_status' => $settings->pro_status,
            'pro_started_at' => $settings->pro_started_at,
            'pro_ends_at' => $settings->pro_ends_at,
            'created_at' => $settings->created_at,
            'updated_at' => $settings->updated_at,
        ];
    }
}
