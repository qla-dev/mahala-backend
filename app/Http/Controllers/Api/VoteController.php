<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostVote;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoteController extends Controller
{
    public function votePost(Request $request, Post $post)
    {
        $validated = $request->validate([
            'value' => ['required', 'integer', Rule::in([-1, 0, 1])],
        ]);

        $userId = $request->user()->id;
        $value = (int) $validated['value'];

        if ($value === 0) {
            PostVote::query()
                ->where('post_id', $post->id)
                ->where('user_id', $userId)
                ->delete();
        } else {
            PostVote::query()->updateOrCreate(
                [
                    'post_id' => $post->id,
                    'user_id' => $userId,
                ],
                ['value' => $value],
            );
            $this->createVoteNotification(
                userId: $post->author_user_id,
                fromUserId: $userId,
                relatedPostId: $post->id,
                relatedCommentId: null,
                value: $value,
            );
        }

        return response()->json([
            'message' => 'Glas za objavu je uspjesno sacuvan.',
            'data' => $this->postVoteSummary($post, $userId),
        ]);
    }

    public function voteComment(Request $request, Comment $comment)
    {
        $validated = $request->validate([
            'value' => ['required', 'integer', Rule::in([-1, 0, 1])],
        ]);

        $userId = $request->user()->id;
        $value = (int) $validated['value'];

        if ($value === 0) {
            CommentVote::query()
                ->where('reply_id', $comment->id)
                ->where('user_id', $userId)
                ->delete();
        } else {
            CommentVote::query()->updateOrCreate(
                [
                    'reply_id' => $comment->id,
                    'user_id' => $userId,
                ],
                ['value' => $value],
            );
            $this->createVoteNotification(
                userId: $comment->author,
                fromUserId: $userId,
                relatedPostId: $comment->post_id,
                relatedCommentId: $comment->id,
                value: $value,
            );
        }

        return response()->json([
            'message' => 'Glas za komentar je uspjesno sacuvan.',
            'data' => $this->commentVoteSummary($comment, $userId),
        ]);
    }

    private function postVoteSummary(Post $post, int $userId): array
    {
        $upvotes = $post->votes()->where('value', 1)->count();
        $downvotes = $post->votes()->where('value', -1)->count();
        $myVote = (int) ($post->votes()->where('user_id', $userId)->value('value') ?? 0);

        return [
            'post_id' => $post->id,
            'upvotes' => $upvotes,
            'downvotes' => $downvotes,
            'score' => $upvotes - $downvotes,
            'my_vote' => $myVote,
        ];
    }

    private function createVoteNotification(?int $userId, int $fromUserId, int $relatedPostId, ?int $relatedCommentId, int $value): void
    {
        if (!$userId || $userId === $fromUserId) {
            return;
        }

        $settings = \App\Models\User::query()->find($userId)?->settings()->firstOrCreate([], [
            'notifications_app' => true,
            'notifications' => true,
            'locale' => 'bs',
            'pro_status' => 0,
        ]);

        if (!$settings?->notifications_app) {
            return;
        }

        Notification::query()->create([
            'user_id' => $userId,
            'from_user_id' => $fromUserId,
            'type' => Notification::TYPE_VOTE,
            'vote_value' => $value,
            'title' => 'vote',
            'body' => $relatedCommentId ? 'comment_vote' : 'post_vote',
            'related_post_id' => $relatedPostId,
            'related_comment_id' => $relatedCommentId,
        ]);
    }

    private function commentVoteSummary(Comment $comment, int $userId): array
    {
        $upvotes = $comment->votes()->where('value', 1)->count();
        $downvotes = $comment->votes()->where('value', -1)->count();
        $myVote = (int) ($comment->votes()->where('user_id', $userId)->value('value') ?? 0);

        return [
            'reply_id' => $comment->id,
            'upvotes' => $upvotes,
            'downvotes' => $downvotes,
            'score' => $upvotes - $downvotes,
            'my_vote' => $myVote,
        ];
    }
}
