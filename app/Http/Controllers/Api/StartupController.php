<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Topic;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StartupController extends Controller
{
    private const GENERAL_TOPIC_COLORS = [
        'glavna' => '#7c3aed',
        'eventi' => '#ec4899',
        'posao' => '#06b6d4',
        'ljubimci' => '#84cc16',
        'izgubljeno-i-nadjeno' => '#fde047',
        'politika' => '#dc2626',
        'nocna-smjena' => '#0b0a10',
        'gaming' => '#8b5e34',
        'sport' => '#ef4444',
        'prodajem-i-kupujem' => '#2dd4bf',
        'dating' => '#ec4899',
    ];

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
            'color_hex' => $topic->color_hex,
            'is_premium' => $topic->is_premium,
            'is_system' => $topic->is_system,
            'status' => $topic->status,
            'created_at' => $topic->created_at,
            'updated_at' => $topic->updated_at,
        ];
    }

    private function formatPost(Post $post): array
    {
        $topic = $this->resolveTopic($post->topic_id, $post->mahala_id);

        return [
            'id' => $post->id,
            'topic_id' => $post->topic_id,
            'author_user_id' => $post->author_user_id,
            'mahala_id' => $post->mahala_id,
            'content' => $post->content,
            'color_hex' => $topic?->color_hex ?? $this->resolveGeneralTopicColor($post->topic_id),
            'image_uri' => $post->image_uri,
            'is_anonymous' => $post->is_anonymous,
            'status' => $post->status,
            'hidden' => $post->hidden,
            'created_at' => $post->created_at,
            'updated_at' => $post->updated_at,
        ];
    }

    private function resolveTopic(?string $topicId, ?string $mahalaId = null): ?Topic
    {
        if (!$topicId) {
            return null;
        }

        if ($mahalaId) {
            return Topic::query()
                ->where('mahala_id', $mahalaId)
                ->where('slug', $topicId)
                ->first();
        }

        return Topic::query()
            ->where('slug', $topicId)
            ->first();
    }

    private function resolveGeneralTopicColor(?string $topicId): string
    {
        return self::GENERAL_TOPIC_COLORS[$topicId ?: 'glavna'] ?? '#7c3aed';
    }
}
