<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blocked;
use App\Services\ContentModerationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentReportController extends Controller
{
    public function store(Request $request, ContentModerationService $moderation)
    {
        $data = $request->validate([
            'content_type' => ['required', Rule::in(['post', 'comment', 'topic'])],
            'content_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'block_user' => ['sometimes', 'boolean'],
        ]);
        $report = $moderation->report($request->user(), $data['content_type'], (int) $data['content_id'], $data['reason'], $data['block_user'] ?? true);

        return response()->json([
            'message' => 'Prijava je zaprimljena. Pregledat ćemo je u roku od 24 sata.',
            'data' => ['id' => $report->id, 'status' => $report->status, 'due_at' => $report->due_at->toIso8601String()],
            'blocked_ids' => Blocked::query()->where('user_id', $request->user()->id)->pluck('blocked_id')->map(fn ($id) => (int) $id)->all(),
        ], 201);
    }
}
