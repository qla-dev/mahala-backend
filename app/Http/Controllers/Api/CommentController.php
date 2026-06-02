<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
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
                ->oldest()
                ->get()
                ->map(fn (Comment $comment) => $this->formatComment($comment, $userId));

            return response()->json([
                'data' => $comments,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Post not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving comments.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request, string $post)
    {
        try {
            Post::query()->findOrFail($post);

            $validated = $request->validate([
                'author' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
                'author_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
                'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('comments', 'id')],
                'content' => ['required', 'string'],
                'is_anonymous' => ['sometimes', 'boolean'],
                'status' => ['sometimes', 'integer'],
            ]);

            $parentId = $validated['parent_id'] ?? null;

            if ($parentId !== null) {
                $parent = Comment::query()->findOrFail($parentId);

                if ((string) $parent->post_id !== (string) $post || $parent->parent_id !== null) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['The selected parent comment is invalid.'],
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

            return response()->json([
                'message' => 'Comment created successfully.',
                'data' => $this->formatComment($comment, $request->user('sanctum')?->id),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Post not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while creating the comment.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while creating the comment.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
