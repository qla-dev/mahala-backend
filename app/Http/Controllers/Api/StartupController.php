<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Topic;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StartupController extends Controller
{
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
            ]);

            $mahalaIds = $this->normalizeMahalaIds($payload['mahala_ids']);
            $scopeIds = $this->withParentTopicScopes($mahalaIds);
            $limit = (int) ($payload['limit'] ?? 10);

            if ($scopeIds === []) {
                return response()->json([
                    'data' => [
                        'topics' => [],
                        'posts' => [],
                    ],
                    'meta' => [
                        'mahala_ids' => $mahalaIds,
                        'scope_ids' => $scopeIds,
                        'posts' => $this->paginationMeta(0, 1, $limit),
                    ],
                ], 200);
            }

            $topics = Topic::query()
                ->whereIn('mahala_id', $scopeIds)
                ->orderBy('is_system', 'desc')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Topic $topic) => $this->formatTopic($topic));

            $paginatedPosts = Post::query()
                ->with(['comments' => fn ($query) => $query->where('status', 1)->with('authorUser')->oldest()])
                ->withCount(['comments as active_comments_count' => fn ($query) => $query->where('status', 1)])
                ->whereIn('mahala_id', $scopeIds)
                ->where(function ($query) {
                    $query->whereNull('hidden')->orWhere('hidden', false);
                })
                ->latest()
                ->paginate($limit, ['*'], 'page', 1);

            $posts = $paginatedPosts
                ->getCollection()
                ->map(fn (Post $post) => $this->formatPost($post));

            return response()->json([
                'data' => [
                    'topics' => $topics,
                    'posts' => $posts,
                ],
                'meta' => [
                    'mahala_ids' => $mahalaIds,
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
                'message' => 'An error occurred while loading startup data.',
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

    private function formatPost(Post $post): array
    {
        $post->loadMissing(['comments' => fn ($query) => $query->where('status', 1)->with('authorUser')->oldest()]);
        $comments = $post->comments
            ->where('status', 1)
            ->values()
            ->map(fn (Comment $comment) => $this->formatComment($comment));

        return [
            'id' => $post->id,
            'topic_id' => $post->topic_id,
            'author_user_id' => $post->author_user_id,
            'mahala_id' => $post->mahala_id,
            'content' => $post->content,
            'color_hex' => $this->resolveMahalaColor($post->mahala_id),
            'image_uri' => $post->image_uri,
            'is_anonymous' => $post->is_anonymous,
            'status' => $post->status,
            'hidden' => $post->hidden,
            'comments_count' => $post->active_comments_count ?? $comments->count(),
            'comments' => $comments,
            'created_at' => $post->created_at,
            'updated_at' => $post->updated_at,
        ];
    }

    private function formatComment(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'post_id' => $comment->post_id,
            'author_user_id' => $comment->author,
            'author_username' => $comment->authorUser?->username,
            'content' => $comment->content,
            'is_anonymous' => $comment->is_anonymous,
            'status' => $comment->status,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
        ];
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
