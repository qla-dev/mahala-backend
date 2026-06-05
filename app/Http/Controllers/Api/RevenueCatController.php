<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RevenueCatController extends Controller
{
    public function syncPro(Request $request)
    {
        $user = $request->user();
        $secretApiKey = config('services.revenuecat.secret_api_key');
        $entitlementId = config('services.revenuecat.pro_entitlement_id', 'pro');

        if (!$secretApiKey) {
            return response()->json([
                'message' => 'RevenueCat secret API key nije konfigurisan.',
            ], 503);
        }

        $appUserId = "mahala-user-{$user->id}";
        $response = Http::withToken($secretApiKey)
            ->acceptJson()
            ->timeout(8)
            ->get("https://api.revenuecat.com/v1/subscribers/{$appUserId}");

        if (!$response->successful()) {
            return response()->json([
                'message' => 'RevenueCat provjera nije uspjela.',
            ], 502);
        }

        $entitlements = $response->json('subscriber.entitlements') ?: [];
        $entitlement = $entitlements[$entitlementId] ?? $this->firstActiveEntitlement($entitlements);
        $resolvedEntitlementId = $entitlementId;

        if (!isset($entitlements[$entitlementId])) {
            $matchedEntitlementId = array_search($entitlement, $entitlements, true);
            $resolvedEntitlementId = is_string($matchedEntitlementId)
                ? $matchedEntitlementId
                : $entitlementId;
        }
        $isActive = $this->entitlementIsActive($entitlement);
        $settings = $user->settings()->firstOrCreate([], [
            'notifications_app' => true,
            'notifications' => true,
            'notifications_app_location' => true,
            'notifications_app_comments' => true,
            'notifications_app_votes' => true,
            'notifications_location' => true,
            'notifications_comments' => true,
            'notifications_votes' => true,
            'locale' => 'bs',
            'pro_status' => UserSetting::PRO_INACTIVE,
        ]);

        if (!$isActive) {
            $settings->forceFill([
                'pro_status' => UserSetting::PRO_INACTIVE,
                'pro_started_at' => null,
                'pro_ends_at' => null,
            ])->save();
        } else {
            $plan = $this->planForEntitlement($entitlement);
            $settings->forceFill([
                'pro_status' => $plan === 'yearly' ? UserSetting::PRO_YEARLY : UserSetting::PRO_MONTHLY,
                'pro_started_at' => $entitlement['purchase_date'] ?? null,
                'pro_ends_at' => $entitlement['expires_date'] ?? null,
            ])->save();
        }

        return response()->json([
            'data' => [
                'app_user_id' => $appUserId,
                'entitlement_id' => $resolvedEntitlementId ?: $entitlementId,
                'active' => $isActive,
                'settings' => [
                    'id' => $settings->id,
                    'user_id' => $settings->user_id,
                    'notifications_app' => $settings->notifications_app,
                    'notifications' => $settings->notifications,
                    'notifications_app_location' => $settings->notifications_app_location,
                    'notifications_app_comments' => $settings->notifications_app_comments,
                    'notifications_app_votes' => $settings->notifications_app_votes,
                    'notifications_location' => $settings->notifications_location,
                    'notifications_comments' => $settings->notifications_comments,
                    'notifications_votes' => $settings->notifications_votes,
                    'locale' => $settings->locale,
                    'pro_status' => $settings->pro_status,
                    'pro_started_at' => $settings->pro_started_at,
                    'pro_ends_at' => $settings->pro_ends_at,
                    'created_at' => $settings->created_at,
                    'updated_at' => $settings->updated_at,
                ],
            ],
        ]);
    }

    private function entitlementIsActive(?array $entitlement): bool
    {
        if (!$entitlement) {
            return false;
        }

        $expiresAt = $entitlement['expires_date'] ?? null;

        return !$expiresAt || now()->lt($expiresAt);
    }

    private function firstActiveEntitlement(array $entitlements): ?array
    {
        foreach ($entitlements as $entitlement) {
            if (is_array($entitlement) && $this->entitlementIsActive($entitlement)) {
                return $entitlement;
            }
        }

        return null;
    }

    private function planForEntitlement(array $entitlement): string
    {
        $productId = strtolower((string) ($entitlement['product_identifier'] ?? ''));

        return str_contains($productId, 'year') || str_contains($productId, 'annual')
            ? 'yearly'
            : 'monthly';
    }
}
