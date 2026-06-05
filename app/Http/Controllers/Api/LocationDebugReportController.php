<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationDebugReport;
use Illuminate\Http\Request;

class LocationDebugReportController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'platform' => ['sometimes', 'nullable', 'string', 'max:32'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payload' => ['required', 'array'],
            'payload.userMahalasApiCalls' => ['sometimes', 'integer', 'min:0'],
            'payload.startupApiCalls' => ['sometimes', 'integer', 'min:0'],
            'payload.backgroundLocationTicks' => ['sometimes', 'integer', 'min:0'],
            'payload.foregroundLocationTicks' => ['sometimes', 'integer', 'min:0'],
            'payload.startupCallHistory' => ['sometimes', 'array'],
        ]);

        $report = LocationDebugReport::query()->create([
            'user_id' => $request->user()?->id,
            'platform' => $validated['platform'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
            'source' => $validated['source'] ?? 'settings-debug',
            'payload' => $validated['payload'],
        ]);

        return response()->json([
            'message' => 'Location debug report je sacuvan.',
            'data' => [
                'id' => $report->id,
                'created_at' => $report->created_at,
            ],
        ], 201);
    }
}
