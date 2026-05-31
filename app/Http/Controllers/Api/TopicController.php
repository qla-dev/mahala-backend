<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TopicController extends Controller
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

    public function currentMahalas(Request $request)
    {
        try {
            $payload = $request->validate([
                'mahala_ids' => ['required'],
            ]);

            $mahalaIds = $this->normalizeMahalaIds($payload['mahala_ids']);
            $topicScopeIds = $this->withParentTopicScopes($mahalaIds);

            if ($topicScopeIds === []) {
                return response()->json([
                    'data' => [],
                ], 200);
            }

            $topics = Topic::query()
                ->whereIn('mahala_id', $topicScopeIds)
                ->orderBy('is_system', 'desc')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Topic $topic) => $this->formatTopic($topic));

            return response()->json([
                'data' => $topics,
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving topics for current mahalas.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $topics = Topic::query()
                ->when($request->filled('mahala_id'), fn ($query) => $query->where('mahala_id', $request->query('mahala_id')))
                ->latest()
                ->get()
                ->map(fn (Topic $topic) => $this->formatTopic($topic));

            return response()->json([
                'data' => $topics,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving topics.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->rules());
            $topic = Topic::query()->create($this->buildAttributes($validated));

            return response()->json([
                'message' => 'Topic created successfully.',
                'data' => $this->formatTopic($topic),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while creating the topic.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while creating the topic.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $topic = Topic::query()->findOrFail($id);

            return response()->json([
                'data' => $this->formatTopic($topic),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Topic not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving the topic.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $topic = Topic::query()->findOrFail($id);
            $validated = $request->validate($this->rules(isUpdate: true, topic: $topic));
            $topic->update($this->buildAttributes($validated, $topic));
            $topic->refresh();

            return response()->json([
                'message' => 'Topic updated successfully.',
                'data' => $this->formatTopic($topic),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Topic not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while updating the topic.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while updating the topic.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $topic = Topic::query()->findOrFail($id);
            $topic->delete();

            return response()->json([
                'message' => 'Topic deleted successfully.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Topic not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while deleting the topic.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while deleting the topic.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function rules(bool $isUpdate = false, ?Topic $topic = null): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'id' => ['prohibited'],
            'mahala_id' => [$required, 'string', 'max:255'],
            'created_by_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'name' => [$required, 'string', 'max:255'],
            'slug' => array_filter([
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('topics', 'slug')->ignore($topic?->getKey(), 'id'),
            ]),
            'description' => [$required, 'string'],
            'color_hex' => [$required, 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_premium' => ['sometimes', 'boolean'],
            'is_system' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'integer'],
        ];
    }

    private function buildAttributes(array $validated, ?Topic $topic = null): array
    {
        $name = $validated['name'] ?? $topic?->name ?? '';

        return [
            'mahala_id' => $validated['mahala_id'] ?? $topic?->mahala_id,
            'created_by_user_id' => array_key_exists('created_by_user_id', $validated)
                ? $validated['created_by_user_id']
                : $topic?->created_by_user_id,
            'name' => $name,
            'slug' => $validated['slug'] ?? $topic?->slug ?? $this->resolveSlug($name),
            'description' => $validated['description'] ?? $topic?->description,
            'color_hex' => $validated['color_hex'] ?? $topic?->color_hex,
            'is_premium' => $validated['is_premium'] ?? $topic?->is_premium ?? false,
            'is_system' => $validated['is_system'] ?? $topic?->is_system ?? false,
            'status' => $validated['status'] ?? $topic?->status ?? 0,
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

    private function resolveSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'topic';
        $slug = $baseSlug;
        $suffix = 2;

        while (Topic::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
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
}
