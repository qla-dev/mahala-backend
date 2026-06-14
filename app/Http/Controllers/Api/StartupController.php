<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Mahala;
use App\Models\Post;
use App\Models\Topic;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StartupController extends Controller
{
    private array $authorRahatlukPointsCache = [];

    private const SARAJEVO_TOPIC_SCOPE_ID = 'sarajevo-71000';

    private const SARAJEVO_POLYGON_IDS = [
        '10863',
        '11584',
        '10847',
        '11550',
        '11568',
        '10839',
        '10871',
        '10928',
        '11592',
    ];

    private const DEFAULT_TOPIC_ICONS = [
        'glavna' => 'chatbubble-ellipses',
        'eventi' => 'calendar',
        'posao' => 'briefcase',
        'ljubimci' => 'paw',
        'izgubljeno-i-nadjeno' => 'search',
        'politika' => 'megaphone',
        'nocna-smjena' => 'moon',
        'gaming' => 'game-controller',
        'sport' => 'football',
        'prodajem-i-kupujem' => 'pricetag',
        'dating' => 'heart',
    ];

    public function __invoke(Request $request)
    {
        try {
            $payload = $request->validate([
                'mahala_ids' => ['required'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
                'sort' => ['sometimes', Rule::in(['recent', 'popular', 'commented'])],
            ]);

            $mahalaIds = $this->normalizeMahalaIds($payload['mahala_ids']);
            $publishedMahalaIds = $this->publishedMahalaIds($mahalaIds);
            $scopeIds = $this->withParentTopicScopes($publishedMahalaIds);
            $limit = (int) ($payload['limit'] ?? 10);
            $sort = $payload['sort'] ?? 'recent';

            if ($scopeIds === []) {
                return response()->json([
                    'data' => [
                        'topics' => [],
                        'posts' => [],
                    ],
                    'meta' => [
                        'mahala_ids' => $mahalaIds,
                        'published_mahala_ids' => $publishedMahalaIds,
                        'scope_ids' => $scopeIds,
                        'posts' => $this->paginationMeta(0, 1, $limit),
                    ],
                ], 200);
            }

            $userId = $request->user('sanctum')?->id;
            $engagementWindowStart = Carbon::now()->subDays(10);

            $topics = Topic::query()
                ->whereIn('mahala_id', $scopeIds)
                ->orderBy('is_system', 'desc')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Topic $topic) => $this->formatTopic($topic));

            $postsQuery = Post::query()
                ->with([
                    'author',
                    'comments' => fn ($query) => $query->where('status', 1)->with('authorUser')->withVoteCounts()->latest(),
                ])
                ->withVoteCounts()
                ->withCount([
                    'views as views_count',
                    'comments as active_comments_count' => fn ($query) => $query->where('status', 1),
                    'comments as recent_comments_count' => fn ($query) => $query
                        ->where('status', 1)
                        ->where('created_at', '>=', $engagementWindowStart),
                    'votes as recent_upvotes_count' => fn ($query) => $query
                        ->where('value', 1)
                        ->where('created_at', '>=', $engagementWindowStart),
                ])
                ->whereIn('mahala_id', $scopeIds)
                ->where('status', 1)
                ->where(function ($query) {
                    $query->whereNull('hidden')->orWhere('hidden', false);
                })
                ->when(
                    $sort === 'popular',
                    fn ($query) => $query->orderByDesc('recent_upvotes_count')->latest(),
                    fn ($query) => $query->when(
                        $sort === 'commented',
                        fn ($query) => $query->orderByDesc('recent_comments_count')->latest(),
                        fn ($query) => $query->latest(),
                    ),
                );

            $paginatedPosts = $postsQuery->paginate($limit, ['*'], 'page', 1);

            $posts = $paginatedPosts
                ->getCollection()
                ->map(fn (Post $post) => $this->formatPost($post, $userId));

            return response()->json([
                'data' => [
                    'topics' => $topics,
                    'posts' => $posts,
                ],
                'meta' => [
                    'mahala_ids' => $mahalaIds,
                    'published_mahala_ids' => $publishedMahalaIds,
                    'scope_ids' => $scopeIds,
                    'posts' => $this->paginationMeta(
                        $paginatedPosts->total(),
                        $paginatedPosts->currentPage(),
                        $paginatedPosts->perPage(),
                    ),
                ],
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju pocetnih podataka.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function normalizeMahalaIds(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return collect($items)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function withParentTopicScopes(array $mahalaIds): array
    {
        $scopeIds = collect($mahalaIds);

        if ($scopeIds->intersect(self::SARAJEVO_POLYGON_IDS)->isNotEmpty()) {
            $scopeIds->push(self::SARAJEVO_TOPIC_SCOPE_ID);
        }

        return $scopeIds
            ->unique()
            ->values()
            ->all();
    }

    private function publishedMahalaIds(array $mahalaIds): array
    {
        if ($mahalaIds === []) {
            return [];
        }

        $publishedIds = Mahala::query()
            ->whereIn('id', $mahalaIds)
            ->where('status', 'published')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $externalScopeIds = array_values(array_intersect($mahalaIds, [
            self::SARAJEVO_TOPIC_SCOPE_ID,
            ...self::SARAJEVO_POLYGON_IDS,
        ]));

        return collect([...$publishedIds, ...$externalScopeIds])
            ->unique()
            ->values()
            ->all();
    }

    private function paginationMeta(int $total, int $page, int $limit): array
    {
        $lastPage = $limit > 0 ? (int) ceil($total / $limit) : 1;
        $lastPage = max(1, $lastPage);

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
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
            'icon' => $this->formatTopicIcon($topic),
            'is_premium' => $topic->is_premium,
            'is_system' => $topic->is_system,
            'status' => $topic->status,
            'created_at' => $topic->created_at,
            'updated_at' => $topic->updated_at,
        ];
    }

    private function formatPost(Post $post, ?int $userId = null): array
    {
        $post->loadMissing([
            'author',
            'comments' => fn ($query) => $query->where('status', 1)->with('authorUser')->withVoteCounts()->latest(),
        ]);
        $comments = $post->comments
            ->where('status', 1)
            ->values()
            ->map(fn (Comment $comment) => $this->formatComment($comment, $userId));
        $upvotes = (int) ($post->upvotes_count ?? $post->votes()->where('value', 1)->count());
        $downvotes = (int) ($post->downvotes_count ?? $post->votes()->where('value', -1)->count());

        return [
            'id' => $post->id,
            'topic_id' => $post->topic_id,
            'author_user_id' => $post->author_user_id,
            'author_username' => $post->author?->username,
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

    private function resolveTopicIcon(?string $slug): string
    {
        return self::DEFAULT_TOPIC_ICONS[$slug ?: 'glavna'] ?? 'chatbubble-ellipses';
    }

    private function formatTopicIcon(Topic $topic): string
    {
        if ($topic->is_system && (!$topic->icon || $topic->icon === 'chatbubble-ellipses')) {
            return $this->resolveTopicIcon($topic->slug);
        }

        return $topic->icon ?: $this->resolveTopicIcon($topic->slug);
    }

    private function resolveMahalaColor(?string $mahalaId): string
    {
        if ($mahalaId === self::SARAJEVO_TOPIC_SCOPE_ID) {
            return '#8b5cf6';
        }

        if (in_array($mahalaId, self::SARAJEVO_POLYGON_IDS, true)) {
            return '#2563eb';
        }

        return '#f59e0b';
    }
}
