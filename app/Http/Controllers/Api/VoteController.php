<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\CommentVote;
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
        }

        return response()->json([
            'message' => 'Post vote saved successfully.',
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
        }

        return response()->json([
            'message' => 'Comment vote saved successfully.',
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
