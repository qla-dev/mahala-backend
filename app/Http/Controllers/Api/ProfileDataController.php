<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Topic;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileDataController extends Controller
{
    private array $authorRahatlukPointsCache = [];

    public function __invoke(Request $request, string $type, int $user)
    {
        try {
            $payload = $request->validate([
                'page' => ['sometimes', 'integer', 'min:1'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
                'sort' => ['sometimes', Rule::in(['recent'])],
            ]);

            $normalizedType = $this->normalizeType($type);
            $viewerId = $request->user('sanctum')?->id;
            $blockedUserIds = $this->blockedUserIds($viewerId);
            $page = (int) ($payload['page'] ?? 1);
            $limit = (int) ($payload['limit'] ?? 10);

            if ($normalizedType === 'topics') {
                return $this->topicResponse($user, $viewerId, $blockedUserIds, $page, $limit);
            }

            return $this->postResponse($normalizedType, $user, $viewerId, $blockedUserIds, $page, $limit);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju podataka profila.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function postResponse(
        string $type,
        int $targetUserId,
        ?int $viewerId,
        array $blockedUserIds,
        int $page,
        int $limit,
    ) {
        $isOwnProfile = $viewerId && (int) $viewerId === (int) $targetUserId;
        $postsQuery = Post::query()
            ->with([
                'comments' => fn ($query) => $this->applyAuthorBlockFilter(
                    $query->where('status', 1),
                    $blockedUserIds,
                    'author',
                )->with('authorUser')->withVoteCounts()->latest(),
            ])
            ->withVoteCounts()
            ->withCount([
                'views as views_count',
                'comments as active_comments_count' => fn ($query) => $this->applyAuthorBlockFilter(
                    $query->where('status', 1),
                    $blockedUserIds,
                    'author',
                ),
            ])
            ->where(function ($query) use ($isOwnProfile) {
                if ($isOwnProfile) {
                    $query->whereIn('status', [0, 1]);
                    return;
                }

                $query->where('status', 1);
            })
            ->where(function ($query) {
                $query->whereNull('hidden')->orWhere('hidden', false);
            });

        if (!$isOwnProfile) {
            $postsQuery->whereHas('mahala', fn ($query) => $query->where('status', 'published'));
        }

        if ($type === 'posts') {
            $postsQuery->where('author_user_id', $targetUserId);
        } elseif ($type === 'comments') {
            $postsQuery->whereHas('comments', fn ($query) => $query
                ->where('author', $targetUserId)
                ->where('status', 1));
        } elseif ($type === 'votes') {
            $postsQuery->whereHas('votes', fn ($query) => $query
                ->where('user_id', $targetUserId)
                ->where('value', '!=', 0));
        }

        $this->applyAuthorBlockFilter($postsQuery, $blockedUserIds, 'author_user_id');
        $paginatedPosts = $postsQuery->latest()->paginate($limit, ['*'], 'page', $page);
        $posts = $paginatedPosts
            ->getCollection()
            ->map(fn (Post $post) => $this->formatPost($post, $viewerId, $blockedUserIds));

        return response()->json([
            'data' => $posts,
            'meta' => $this->paginationMeta(
                $paginatedPosts->total(),
                $paginatedPosts->currentPage(),
                $paginatedPosts->perPage(),
            ),
        ], 200);
    }

    private function topicResponse(
        int $targetUserId,
        ?int $viewerId,
        array $blockedUserIds,
        int $page,
        int $limit,
    ) {
        $isOwnProfile = $viewerId && (int) $viewerId === (int) $targetUserId;
        $topicsQuery = Topic::query()
            ->where('created_by_user_id', $targetUserId);

        if (!$isOwnProfile) {
            $topicsQuery->whereHas('mahala', fn ($query) => $query->where('status', 'published'));
        }

        $this->applyAuthorBlockFilter($topicsQuery, $blockedUserIds, 'created_by_user_id');
        $paginatedTopics = $topicsQuery->latest()->paginate($limit, ['*'], 'page', $page);
        $topics = $paginatedTopics
            ->getCollection()
            ->map(fn (Topic $topic) => $this->formatTopic($topic));

        return response()->json([
            'data' => $topics,
            'meta' => $this->paginationMeta(
                $paginatedTopics->total(),
                $paginatedTopics->currentPage(),
                $paginatedTopics->perPage(),
            ),
        ], 200);
    }

    private function normalizeType(string $type): string
    {
        return match (strtolower($type)) {
            'posts' => 'posts',
            'topics' => 'topics',
            'comments', 'replies' => 'comments',
            'votes' => 'votes',
            default => throw ValidationException::withMessages([
                'type' => ['Nepoznat tip podataka profila.'],
            ]),
        };
    }

    private function paginationMeta(int $total, int $page, int $limit): array
    {
        $lastPage = $limit > 0 ? (int) ceil($total / $limit) : 1;

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'last_page' => max(1, $lastPage),
            'has_more' => $page < max(1, $lastPage),
        ];
    }

    private function formatPost(Post $post, ?int $userId = null, array $blockedUserIds = []): array
    {
        $post->loadMissing([
            'author',
            'comments' => fn ($query) => $this->applyAuthorBlockFilter(
                $query->where('status', 1),
                $blockedUserIds,
                'author',
            )->with('authorUser')->withVoteCounts()->latest(),
        ]);

        $comments = $post->comments
            ->where('status', 1)
            ->reject(fn (Comment $comment) => $this->isBlockedAuthor($comment->author, $blockedUserIds))
            ->values()
            ->map(fn (Comment $comment) => $this->formatComment($comment, $userId));
        $upvotes = (int) ($post->upvotes_count ?? $post->votes()->where('value', 1)->count());
        $downvotes = (int) ($post->downvotes_count ?? $post->votes()->where('value', -1)->count());

        return [
            'id' => $post->id,
            'topic_id' => $post->topic_id,
            'author_user_id' => $post->author_user_id,
            'author_username' => $post->author?->username,
            'author_rahatluk_points' => $this->authorRahatlukPoints($post->author_user_id),
            'mahala_id' => $post->mahala_id,
            'content' => $post->content,
            'color_hex' => $this->resolveMahalaColor($post->mahala_id),
            'image_uri' => $post->image_uri,
            'is_anonymous' => $post->is_anonymous,
            'status' => $post->status,
            'hidden' => $post->hidden,
            'upvotes' => $upvotes,
            'downvotes' => $downvotes,
            'score' => $upvotes - $downvotes,
            'views_count' => (int) ($post->views_count ?? $post->views()->count()),
            'my_vote' => $userId
                ? (int) ($post->votes()->where('user_id', $userId)->value('value') ?? 0)
                : 0,
            'comments_count' => $post->active_comments_count ?? $comments->count(),
            'comments' => $comments,
            'created_at' => $post->created_at,
            'updated_at' => $post->updated_at,
        ];
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
            'author_display_name' => $comment->is_anonymous
                ? 'komšija'
                : $comment->authorUser?->username ?? 'komšija',
            'author_rahatluk_points' => $this->authorRahatlukPoints($comment->author),
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

    private function formatTopic(Topic $topic): array
    {
        return [
            'id' => $topic->id,
            'mahala_id' => $topic->mahala_id,
            'created_by_user_id' => $topic->created_by_user_id,
            'name' => $topic->name,
            'slug' => $topic->slug,
            'description' => $topic->description,
            'icon' => $topic->icon ?: 'chatbubble-ellipses',
            'is_premium' => $topic->is_premium,
            'is_system' => $topic->is_system,
            'status' => $topic->status,
            'created_at' => $topic->created_at,
            'updated_at' => $topic->updated_at,
        ];
    }

    private function blockedUserIds(?int $userId): array
    {
        if (!$userId) {
            return [];
        }

        return DB::table('blocked')
            ->where('user_id', $userId)
            ->pluck('blocked_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function applyAuthorBlockFilter($query, array $blockedUserIds, string $column)
    {
        if ($blockedUserIds === []) {
            return $query;
        }

        return $query->where(function ($query) use ($blockedUserIds, $column) {
            $query->whereNull($column)->orWhereNotIn($column, $blockedUserIds);
        });
    }

    private function isBlockedAuthor($authorId, array $blockedUserIds): bool
    {
        return $authorId !== null && in_array((int) $authorId, $blockedUserIds, true);
    }

    private function resolveMahalaColor($mahalaId): string
    {
        if (!$mahalaId) {
            return '#7c3aed';
        }

        $colors = [
            '#7c3aed',
            '#0891b2',
            '#16a34a',
            '#eab308',
            '#f97316',
            '#dc2626',
            '#db2777',
        ];

        return $colors[abs((int) $mahalaId) % count($colors)];
    }

    private function authorRahatlukPoints(?int $authorId): int
    {
        if (!$authorId) {
            return 0;
        }

        if (array_key_exists($authorId, $this->authorRahatlukPointsCache)) {
            return $this->authorRahatlukPointsCache[$authorId];
        }

        $postVotes = DB::table('post_votes')
            ->join('posts', 'posts.id', '=', 'post_votes.post_id')
            ->where('posts.author_user_id', $authorId)
            ->selectRaw(
                'SUM(CASE WHEN post_votes.value = 1 THEN 1 ELSE 0 END) as positive_votes, ' .
                'SUM(CASE WHEN post_votes.value = -1 THEN 1 ELSE 0 END) as negative_votes'
            )
            ->first();

        $commentVotes = DB::table('comment_votes')
            ->join('comments', 'comments.id', '=', 'comment_votes.reply_id')
            ->where('comments.author', $authorId)
            ->selectRaw(
                'SUM(CASE WHEN comment_votes.value = 1 THEN 1 ELSE 0 END) as positive_votes, ' .
                'SUM(CASE WHEN comment_votes.value = -1 THEN 1 ELSE 0 END) as negative_votes'
            )
            ->first();

        $positiveVotes = (int) ($postVotes->positive_votes ?? 0) + (int) ($commentVotes->positive_votes ?? 0);
        $negativeVotes = (int) ($postVotes->negative_votes ?? 0) + (int) ($commentVotes->negative_votes ?? 0);

        return $this->authorRahatlukPointsCache[$authorId] = $positiveVotes - $negativeVotes;
    }
}
