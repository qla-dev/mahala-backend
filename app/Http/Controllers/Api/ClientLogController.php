<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientLogController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:64'],
            'message' => ['required', 'string', 'max:65535'],
            'context' => ['required', 'array'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:32'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $log = ClientLog::query()->create([
            'user_id' => Auth::guard('sanctum')->user()?->id,
            'level' => 'error',
            'source' => $validated['source'],
            'message' => $validated['message'],
            'context' => $validated['context'],
            'platform' => $validated['platform'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Log je sačuvan.',
            'data' => [
                'id' => $log->id,
                'created_at' => $log->created_at,
            ],
        ], 201);
    }
}
