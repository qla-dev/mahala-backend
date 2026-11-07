<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\SupportRequest;
use App\Services\ContentModerationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModerationController extends Controller
{
    private function authorizeModerator(Request $request): void
    {
        abort_unless(in_array((int) $request->user()->id, config('moderation.moderator_ids', []), true), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeModerator($request);
        $data = $request->validate(['status' => ['sometimes', Rule::in(['pending', 'removed', 'dismissed'])]]);

        return response()->json([
            'alerts' => [
                'unread' => ContentReport::query()->where('status', 'pending')->whereNull('seen_at')->count(),
                'due_soon' => ContentReport::query()->where('status', 'pending')->whereBetween('due_at', [now(), now()->addHours(4)])->count(),
                'overdue' => ContentReport::query()->where('status', 'pending')->where('due_at', '<=', now())->count(),
            ],
            'reports' => ContentReport::query()->where('status', $data['status'] ?? 'pending')->orderBy('due_at')->paginate(50),
        ]);
    }

    public function update(Request $request, ContentReport $report, ContentModerationService $moderation)
    {
        $this->authorizeModerator($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['seen', 'remove', 'dismiss'])],
            'note' => ['required_unless:action,seen', 'nullable', 'string', 'max:4000'],
        ]);

        if ($data['action'] === 'seen') {
            $report->forceFill(['seen_at' => $report->seen_at ?? now()])->save();
        } else {
            abort_unless($report->status === 'pending', 409, 'Report has already been reviewed.');
            $moderation->review($report, $data['action'], 'user:'.$request->user()->id, $data['note']);
        }

        return response()->json(['data' => $report->fresh()]);
    }

    public function support(Request $request)
    {
        $this->authorizeModerator($request);
        return response()->json(['data' => SupportRequest::query()->whereNull('resolved_at')->oldest()->paginate(50)]);
    }

    public function resolveSupport(Request $request, SupportRequest $ticket)
    {
        $this->authorizeModerator($request);
        $ticket->update(['resolved_at' => now()]);
        return response()->json(['message' => 'Zahtjev je označen kao riješen.']);
    }
}
