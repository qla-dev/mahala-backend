<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Topic;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PostController extends Controller
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

    public function feed(Request $request)
    {
        try {
            $payload = $request->validate([
                'mahala_ids' => ['required'],
            ]);

            $mahalaIds = $this->normalizeMahalaIds($payload['mahala_ids']);
            $feedScopeIds = $this->withParentTopicScopes($mahalaIds);

            if ($feedScopeIds === []) {
                return response()->json([
                    'data' => [],
                ], 200);
            }

            $posts = Post::query()
                ->whereIn('mahala_id', $feedScopeIds)
                ->where(function ($query) {
                    $query->whereNull('hidden')->orWhere('hidden', false);
                })
                ->latest()
                ->get()
                ->map(fn (Post $post) => $this->formatPost($post));

            return response()->json([
                'data' => $posts,
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving feed posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $posts = Post::query()
                ->when($request->filled('topic_id'), fn ($query) => $query->where('topic_id', $request->query('topic_id')))
                ->when($request->filled('channel_id'), fn ($query) => $query->where(
                    'topic_id',
                    $this->normalizeTopicId($request->query('channel_id'), $request->query('mahala_id')),
                ))
                ->when($request->filled('mahala_id'), fn ($query) => $query->where('mahala_id', $request->query('mahala_id')))
                ->latest()
                ->get()
                ->map(fn (Post $post) => $this->formatPost($post));

            return response()->json([
                'data' => $posts,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->rules());
            $post = Post::query()->create($this->buildAttributes($validated));

            return response()->json([
                'message' => 'Post created successfully.',
                'data' => $this->formatPost($post),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while creating the post.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while creating the post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $post = Post::query()->findOrFail($id);

            return response()->json([
                'data' => $this->formatPost($post),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Post not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving the post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $post = Post::query()->findOrFail($id);
            $validated = $request->validate($this->rules(isUpdate: true));
            $post->update($this->buildAttributes($validated, $post));
            $post->refresh();

            return response()->json([
                'message' => 'Post updated successfully.',
                'data' => $this->formatPost($post),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Post not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while updating the post.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while updating the post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $post = Post::query()->findOrFail($id);
            $post->delete();

            return response()->json([
                'message' => 'Post deleted successfully.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Post not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while deleting the post.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while deleting the post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';
        $topicRequired = $isUpdate ? 'sometimes' : 'required_without:channel_id';

        return [
            'id' => ['prohibited'],
            'topic_id' => [$topicRequired, 'string', 'max:255'],
            'channel_id' => ['sometimes', 'string', 'max:255'],
            'author_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'mahala_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string'],
            'image_uri' => ['sometimes', 'nullable', 'string'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'integer'],
            'hidden' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    private function buildAttributes(array $validated, ?Post $post = null): array
    {
        $mahalaId = $validated['mahala_id'] ?? $post?->mahala_id;
        $topicId = $this->normalizeTopicId(
            $validated['topic_id'] ?? $validated['channel_id'] ?? $post?->topic_id,
            $mahalaId,
        );
        $topic = $this->resolveTopic(
            $topicId,
            $mahalaId,
        );

        return [
            'topic_id' => $topicId,
            'author_user_id' => array_key_exists('author_user_id', $validated)
                ? $validated['author_user_id']
                : $post?->author_user_id,
            'mahala_id' => array_key_exists('mahala_id', $validated)
                ? $validated['mahala_id']
                : $post?->mahala_id ?? $topic?->mahala_id,
            'content' => array_key_exists('content', $validated) ? $validated['content'] : $post?->content,
            'image_uri' => array_key_exists('image_uri', $validated) ? $validated['image_uri'] : $post?->image_uri,
            'is_anonymous' => $validated['is_anonymous'] ?? $post?->is_anonymous ?? true,
            'status' => $validated['status'] ?? $post?->status ?? 0,
            'hidden' => array_key_exists('hidden', $validated) ? $validated['hidden'] : $post?->hidden,
        ];
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

    private function normalizeTopicId(?string $topicId, ?string $mahalaId = null): ?string
    {
        if (!$topicId) {
            return $topicId;
        }

        if ($mahalaId) {
            $suffix = "-{$mahalaId}";

            if (str_ends_with($topicId, $suffix)) {
                return substr($topicId, 0, -strlen($suffix));
            }
        }

        return $topicId;
    }
}
