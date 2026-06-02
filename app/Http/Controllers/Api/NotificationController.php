<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 100));

        $notifications = Notification::query()
            ->with(['fromUser', 'relatedPost', 'relatedComment'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Notification $notification) => $this->formatNotification($notification));

        return response()->json([
            'data' => $notifications,
        ]);
    }

    private function formatNotification(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'user_id' => $notification->user_id,
            'from_user_id' => $notification->from_user_id,
            'from_username' => $notification->fromUser?->username,
            'type' => $notification->type,
            'vote_value' => $notification->vote_value,
            'title' => $notification->title,
            'body' => $notification->body,
            'related_post_id' => $notification->related_post_id,
            'related_comment_id' => $notification->related_comment_id,
            'related_post_content' => $notification->relatedPost?->content,
            'related_comment_content' => $notification->relatedComment?->content,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }
}
