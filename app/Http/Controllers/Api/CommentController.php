<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Services\ExpoPushNotificationService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    public function index(Request $request, string $post)
    {
        try {
            Post::query()->findOrFail($post);
            $userId = $request->user('sanctum')?->id;

            $comments = Comment::query()
                ->with('authorUser')
                ->withVoteCounts()
                ->where('post_id', $post)
                ->where('status', 1)
                ->latest()
                ->get()
                ->map(fn (Comment $comment) => $this->formatComment($comment, $userId));

            return response()->json([
                'data' => $comments,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Objava nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju komentara.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request, string $post)
    {
        try {
            $postModel = Post::query()->findOrFail($post);

            $validated = $request->validate([
                'author' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
                'author_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
                'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('comments', 'id')],
                'content' => ['required', 'string'],
                'is_anonymous' => ['sometimes', 'boolean'],
                'status' => ['sometimes', 'integer'],
            ]);

            $parentId = $validated['parent_id'] ?? null;
            $parent = null;

            if ($parentId !== null) {
                $parent = Comment::query()->findOrFail($parentId);

                if ((string) $parent->post_id !== (string) $post || $parent->parent_id !== null) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['Odabrani roditeljski komentar nije ispravan.'],
                    ]);
                }
            }

            $comment = Comment::query()->create([
                'post_id' => $post,
                'parent_id' => $parentId,
                'author' => $validated['author'] ?? $validated['author_user_id'] ?? null,
                'content' => $validated['content'],
                'is_anonymous' => $validated['is_anonymous'] ?? true,
                'status' => $validated['status'] ?? 1,
            ]);
            $comment->load('authorUser');
            $this->createCommentNotifications($postModel, $comment, $parent);

            return response()->json([
                'message' => 'Komentar je uspjesno kreiran.',
                'data' => $this->formatComment($comment, $request->user('sanctum')?->id),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Objava nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri kreiranju komentara.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri kreiranju komentara.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function createCommentNotifications(Post $post, Comment $comment, ?Comment $parent): void
    {
        $this->createCommentReplyNotification($post, $comment, $parent);

        if ($parent && $post->author_user_id && (string) $post->author_user_id === (string) $parent->author) {
            return;
        }

        $this->createPostCommentNotification($post, $comment);
    }

    private function createPostCommentNotification(Post $post, Comment $comment): void
    {
        if (!$post->author_user_id || (string) $post->author_user_id === (string) $comment->author) {
            return;
        }

        $settings = $post->author?->settings()->firstOrCreate([], [
            'notifications_app' => true,
            'notifications' => true,
            'notifications_app_location' => true,
            'notifications_app_comments' => true,
            'notifications_app_votes' => true,
            'notifications_location' => true,
            'notifications_comments' => true,
            'notifications_votes' => true,
            'locale' => 'bs',
            'pro_status' => 0,
        ]);

        if (!$settings?->notifications_app || !$settings->notifications_app_comments) {
            return;
        }

        $notification = Notification::query()->create([
            'user_id' => $post->author_user_id,
            'from_user_id' => $comment->is_anonymous ? null : $comment->author,
            'type' => Notification::TYPE_COMMENT,
            'title' => 'comment',
            'body' => 'post_comment',
            'related_post_id' => $post->id,
            'related_comment_id' => $comment->id,
        ]);

        app(ExpoPushNotificationService::class)->sendNotification($notification);
    }

    private function createCommentReplyNotification(Post $post, Comment $comment, ?Comment $parent): void
    {
        if (!$parent?->author || (string) $parent->author === (string) $comment->author) {
            return;
        }

        $settings = $parent->authorUser?->settings()->firstOrCreate([], [
            'notifications_app' => true,
            'notifications' => true,
            'notifications_app_location' => true,
            'notifications_app_comments' => true,
            'notifications_app_votes' => true,
            'notifications_location' => true,
            'notifications_comments' => true,
            'notifications_votes' => true,
            'locale' => 'bs',
            'pro_status' => 0,
        ]);

        if (!$settings?->notifications_app || !$settings->notifications_app_comments) {
            return;
        }

        $notification = Notification::query()->create([
            'user_id' => $parent->author,
            'from_user_id' => $comment->is_anonymous ? null : $comment->author,
            'type' => Notification::TYPE_COMMENT_REPLY,
            'title' => 'comment_reply',
            'body' => 'comment_reply',
            'related_post_id' => $post->id,
            'related_comment_id' => $comment->id,
        ]);

        app(ExpoPushNotificationService::class)->sendNotification($notification);
    }

    private function formatComment(Comment $comment, ?int $userId = null): array
    {
        $upvotes = (int) ($comment->upvotes_count ?? $comment->votes()->where('value', 1)->count());
        $downvotes = (int) ($comment->downvotes_count ?? $comment->votes()->where('value', -1)->count());

        return [
            'id' => $comment->id,
            'post_id' => $comment->post_id,
            'parent_id' => $comment->parent_id,
            'author_user_id' => $comment->author,
            'author_username' => $comment->authorUser?->username,
            'content' => $comment->content,
            'is_anonymous' => $comment->is_anonymous,
            'status' => $comment->status,
            'upvotes' => $upvotes,
            'downvotes' => $downvotes,
            'score' => $upvotes - $downvotes,
            'my_vote' => $userId
                ? (int) ($comment->votes()->where('user_id', $userId)->value('value') ?? 0)
                : 0,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
        ];
    }
}
