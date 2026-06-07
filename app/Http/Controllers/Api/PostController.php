<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Topic;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PostController extends Controller
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

    public function feed(Request $request)
    {
        try {
            $payload = $request->validate([
                'mahala_ids' => ['required'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
                'sort' => ['sometimes', Rule::in(['recent', 'popular', 'commented'])],
            ]);

            $mahalaIds = $this->normalizeMahalaIds($payload['mahala_ids']);
            $feedScopeIds = $this->withParentTopicScopes($mahalaIds);
            $page = (int) ($payload['page'] ?? 1);
            $limit = (int) ($payload['limit'] ?? 10);
            $sort = $payload['sort'] ?? 'recent';

            if ($feedScopeIds === []) {
                return response()->json([
                    'data' => [],
                    'meta' => $this->paginationMeta(0, $page, $limit),
                ], 200);
            }

            $userId = $request->user('sanctum')?->id;
            $engagementWindowStart = Carbon::now()->subDays(10);

            $postsQuery = Post::query()
                ->with(['comments' => fn ($query) => $query->where('status', 1)->with('authorUser')->withVoteCounts()->latest()])
                ->withVoteCounts()
                ->withCount([
                    'comments as active_comments_count' => fn ($query) => $query->where('status', 1),
                    'comments as recent_comments_count' => fn ($query) => $query
                        ->where('status', 1)
                        ->where('created_at', '>=', $engagementWindowStart),
                    'votes as recent_upvotes_count' => fn ($query) => $query
                        ->where('value', 1)
                        ->where('created_at', '>=', $engagementWindowStart),
                ])
                ->whereIn('mahala_id', $feedScopeIds)
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

            $paginatedPosts = $postsQuery->paginate($limit, ['*'], 'page', $page);

            $posts = $paginatedPosts
                ->getCollection()
                ->map(fn (Post $post) => $this->formatPost($post, $userId));

            return response()->json([
                'data' => $posts,
                'meta' => $this->paginationMeta(
                    $paginatedPosts->total(),
                    $paginatedPosts->currentPage(),
                    $paginatedPosts->perPage(),
                ),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju objava iz feeda.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $userId = $request->user('sanctum')?->id;

            $posts = Post::query()
                ->with(['comments' => fn ($query) => $query->where('status', 1)->with('authorUser')->withVoteCounts()->latest()])
                ->withVoteCounts()
                ->withCount(['comments as active_comments_count' => fn ($query) => $query->where('status', 1)])
                ->when($request->filled('topic_id'), fn ($query) => $query->where('topic_id', $request->query('topic_id')))
                ->when($request->filled('channel_id'), fn ($query) => $query->where(
                    'topic_id',
                    $this->normalizeTopicId($request->query('channel_id'), $request->query('mahala_id')),
                ))
                ->when($request->filled('mahala_id'), fn ($query) => $query->where('mahala_id', $request->query('mahala_id')))
                ->latest()
                ->get()
                ->map(fn (Post $post) => $this->formatPost($post, $userId));

            return response()->json([
                'data' => $posts,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju objava.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->rules());
            $attributes = $this->buildAttributes($validated);
            $attributes['image_uri'] = $this->storeUploadedImage($request);
            $post = Post::query()->create($attributes);

            return response()->json([
                'message' => 'Objava je uspjesno kreirana.',
                'data' => $this->formatPost($post, $request->user('sanctum')?->id),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri kreiranju objave.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri kreiranju objave.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        try {
            $post = Post::query()
                ->with(['comments' => fn ($query) => $query->where('status', 1)->with('authorUser')->withVoteCounts()->latest()])
                ->withVoteCounts()
                ->withCount(['comments as active_comments_count' => fn ($query) => $query->where('status', 1)])
                ->findOrFail($id);

            return response()->json([
                'data' => $this->formatPost($post, $request->user('sanctum')?->id),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Objava nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju objave.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $post = Post::query()->findOrFail($id);
            $validated = $request->validate($this->rules(isUpdate: true));
            $attributes = $this->buildAttributes($validated, $post);

            if ($request->hasFile('image')) {
                $attributes['image_uri'] = $this->storeUploadedImage($request, $post->image_uri);
            }

            $post->update($attributes);
            $post->refresh();

            return response()->json([
                'message' => 'Objava je uspjesno azurirana.',
                'data' => $this->formatPost($post, $request->user('sanctum')?->id),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Objava nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri azuriranju objave.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri azuriranju objave.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $post = Post::query()->findOrFail($id);
            $this->deleteStoredImage($post->image_uri);
            $post->delete();

            return response()->json([
                'message' => 'Objava je uspjesno obrisana.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Objava nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri brisanju objave.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri brisanju objave.',
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
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
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

    private function storeUploadedImage(Request $request, ?string $oldImageUri = null): ?string
    {
        if (!$request->hasFile('image')) {
            return null;
        }

        $file = $request->file('image');

        if (!$file instanceof UploadedFile) {
            return null;
        }

        $source = @imagecreatefromstring(File::get($file->getRealPath()));

        if (!$source) {
            throw ValidationException::withMessages([
                'image' => ['Slika nije podrzana ili je ostecena.'],
            ]);
        }

        [$sourceWidth, $sourceHeight] = getimagesize($file->getRealPath()) ?: [0, 0];
        $targetWidth = max(1, (int) $sourceWidth);
        $targetHeight = max(1, (int) $sourceHeight);
        $encoded = null;

        foreach ([1600, 1400, 1200, 1000, 800, 640, 520, 420, 320, 240, 180, 120] as $maxDimension) {
            $scale = min(1, $maxDimension / max($targetWidth, $targetHeight));
            $resizeWidth = max(1, (int) floor($targetWidth * $scale));
            $resizeHeight = max(1, (int) floor($targetHeight * $scale));
            $canvas = imagecreatetruecolor($resizeWidth, $resizeHeight);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $resizeWidth, $resizeHeight, $targetWidth, $targetHeight);

            foreach ([82, 74, 66, 58, 50, 42, 34, 28] as $quality) {
                ob_start();
                imagejpeg($canvas, null, $quality);
                $candidate = ob_get_clean();

                if (strlen($candidate) <= 100 * 1024) {
                    $encoded = $candidate;
                    break;
                }
            }

            if ($encoded === null && $maxDimension === 120) {
                ob_start();
                imagejpeg($canvas, null, 24);
                $encoded = ob_get_clean();
            }

            imagedestroy($canvas);

            if ($encoded !== null) {
                break;
            }
        }

        imagedestroy($source);

        $relativeDirectory = 'uploads/posts/'.now()->format('Y/m');
        $directory = public_path($relativeDirectory);
        File::ensureDirectoryExists($directory, 0755, true);

        $filename = Str::uuid()->toString().'.jpg';
        File::put($directory.DIRECTORY_SEPARATOR.$filename, $encoded);
        $this->deleteStoredImage($oldImageUri);

        return '/'.$relativeDirectory.'/'.$filename;
    }

    private function deleteStoredImage(?string $imageUri): void
    {
        if (!$imageUri) {
            return;
        }

        $path = parse_url($imageUri, PHP_URL_PATH);

        if (!$path || !str_starts_with($path, '/uploads/posts/')) {
            return;
        }

        $absolutePath = public_path(ltrim($path, '/'));

        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
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
