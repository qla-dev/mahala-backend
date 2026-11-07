<?php

namespace App\Services;

use App\Models\Blocked;
use App\Models\Comment;
use App\Models\ContentReport;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContentModerationService
{
    public function report(User $reporter, string $type, int $id, string $reason, bool $blockUser = true): ContentReport
    {
        $target = match ($type) {
            'post' => Post::query()->findOrFail($id),
            'comment' => Comment::query()->findOrFail($id),
            'topic' => Topic::query()->findOrFail($id),
            'user' => User::query()->findOrFail($id),
        };
        $authorId = match ($type) {
            'post' => $target->author_user_id,
            'comment' => $target->author,
            'topic' => $target->created_by_user_id,
            'user' => $target->id,
        };

        if ((int) $authorId === (int) $reporter->id) {
            throw ValidationException::withMessages(['content_id' => ['Ne možeš prijaviti svoj sadržaj ili račun.']]);
        }

        $report = DB::transaction(function () use ($reporter, $type, $id, $reason, $blockUser, $authorId, $target) {
            $report = ContentReport::query()->firstOrCreate([
                'fingerprint' => hash('sha256', $reporter->id.':'.$type.':'.$id),
            ], [
                'reporter_id' => $reporter->id,
                'reported_user_id' => $authorId,
                'content_type' => $type,
                'content_id' => $id,
                'content_snapshot' => $type === 'topic'
                    ? $target->name."\n".$target->description
                    : ($type === 'user' ? 'User blocked; no specific content supplied.' : $target->content),
                'image_uri' => $type === 'post' ? $target->image_uri : null,
                'reason' => $reason,
                'due_at' => now()->addHours(24),
            ]);

            if ($blockUser && $authorId) {
                Blocked::query()->firstOrCreate(['user_id' => $reporter->id, 'blocked_id' => $authorId]);
            }

            return $report;
        });

        return $report;
    }

    public function review(ContentReport $report, string $action, string $reviewer, string $note): void
    {
        if (!in_array($action, ['remove', 'dismiss'], true)) {
            throw new \InvalidArgumentException('Action must be remove or dismiss.');
        }

        DB::transaction(function () use ($report, $action, $reviewer, $note) {
            $report = ContentReport::query()->lockForUpdate()->findOrFail($report->id);
            if ($report->status !== 'pending') {
                throw new \RuntimeException('Report has already been reviewed.');
            }

            if ($action === 'remove') {
                // Keep the records for investigation while removing them from public queries.
                match ($report->content_type) {
                    'post' => Post::query()->whereKey($report->content_id)->update(['status' => 0, 'hidden' => true]),
                    'comment' => Comment::query()->whereKey($report->content_id)->update(['status' => 0]),
                    'topic' => Topic::query()->whereKey($report->content_id)->update(['status' => 0]),
                    default => null,
                };
                $user = User::query()->lockForUpdate()->find($report->reported_user_id);
                if ($user) {
                    $user->forceFill(['suspended_at' => now(), 'suspension_reason' => $note])->save();
                    $user->tokens()->delete();
                    Post::query()->where('author_user_id', $user->id)->update(['status' => 0, 'hidden' => true]);
                    Comment::query()->where('author', $user->id)->update(['status' => 0]);
                    Topic::query()->where('created_by_user_id', $user->id)->update(['status' => 0]);
                }
            }

            $report->forceFill([
                'status' => $action === 'remove' ? 'removed' : 'dismissed',
                'resolved_at' => now(), 'reviewed_by' => $reviewer, 'resolution_note' => $note,
            ])->save();
        });
    }
}
