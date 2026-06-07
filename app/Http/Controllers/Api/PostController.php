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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                ->where('status', 1)
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

            try {
                $this->postAiCheck($attributes, $attributes['image_uri']);
            } catch (Exception $e) {
                $this->deleteStoredImage($attributes['image_uri']);
                throw $e;
            }

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
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:20480'],
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

    private function postAiCheck(array $attributes, ?string $imageUri = null): void
    {
        if (!config('services.post_ai_moderation.enabled')) {
            Log::info('[MAHALA][post-ai] moderation skipped because it is disabled');

            return;
        }

        $apiKey = trim((string) config('services.openrouter.api_key'));

        if ($apiKey === '') {
            Log::warning('[MAHALA][post-ai] moderation unavailable because OPENROUTER_API_KEY is missing');

            throw ValidationException::withMessages([
                'content' => ['AI provjera trenutno nije dostupna. Pokusaj ponovo kasnije.'],
            ]);
        }

        $hasImage = $imageUri !== null;
        $model = trim((string) config(
            $hasImage ? 'services.post_ai_moderation.vision_model' : 'services.post_ai_moderation.text_model',
        ));
        $baseUrl = rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $client = Http::withToken($apiKey)
            ->timeout((int) config('services.post_ai_moderation.timeout', 45))
            ->acceptJson()
            ->asJson();
        $headers = array_filter([
            'HTTP-Referer' => trim((string) config('services.openrouter.http_referer')),
            'X-Title' => trim((string) config('services.openrouter.title')),
        ], fn ($value) => $value !== '');

        if ($headers !== []) {
            $client = $client->withHeaders($headers);
        }

        $userContent = [
            [
                'type' => 'text',
                'text' => json_encode([
                    'content' => (string) ($attributes['content'] ?? ''),
                    'topic_id' => (string) ($attributes['topic_id'] ?? ''),
                    'mahala_id' => (string) ($attributes['mahala_id'] ?? ''),
                    'has_image' => $hasImage,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        if ($hasImage) {
            $imagePart = $this->buildModerationImagePart($imageUri);

            if ($imagePart !== null) {
                $userContent[] = $imagePart;
            }
        }

        $response = $client->post($baseUrl.'/chat/completions', [
            'model' => $model,
            'temperature' => 0,
            'provider' => [
                'require_parameters' => true,
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'mahala_post_moderation',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['allowed', 'reason'],
                        'properties' => [
                            'allowed' => ['type' => 'boolean'],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->postModerationPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
        ]);

        if (!$response->successful()) {
            Log::warning('[MAHALA][post-ai] OpenRouter moderation request failed', [
                'status' => $response->status(),
                'model' => $model,
                'has_image' => $hasImage,
                'body' => Str::limit($response->body(), 2000),
            ]);

            throw ValidationException::withMessages([
                'content' => ['AI provjera nije uspjela. Pokusaj ponovo kasnije.'],
            ]);
        }

        $moderationText = $this->extractModerationText($response->json() ?: []);
        $payload = json_decode($moderationText, true);

        if (!is_array($payload) || !array_key_exists('allowed', $payload)) {
            Log::warning('[MAHALA][post-ai] OpenRouter moderation response was not valid JSON', [
                'model' => $model,
                'has_image' => $hasImage,
                'content' => Str::limit($moderationText, 2000),
            ]);

            throw ValidationException::withMessages([
                'content' => ['AI provjera nije vratila validan rezultat. Pokusaj ponovo kasnije.'],
            ]);
        }

        if (!$payload['allowed']) {
            Log::info('[MAHALA][post-ai] post rejected by moderation', [
                'reason' => Str::limit((string) ($payload['reason'] ?? ''), 500),
                'model' => $model,
                'has_image' => $hasImage,
            ]);

            $this->deleteStoredImage($imageUri);

            throw ValidationException::withMessages([
                'content' => [$payload['reason'] ?: 'Objava nije prosla sigurnosnu provjeru.'],
            ]);
        }
    }

    private function buildModerationImagePart(string $imageUri): ?array
    {
        $path = parse_url($imageUri, PHP_URL_PATH);

        if (!$path || !str_starts_with($path, '/uploads/posts/')) {
            return null;
        }

        $absolutePath = public_path(ltrim($path, '/'));

        if (!File::exists($absolutePath)) {
            return null;
        }

        $mime = File::mimeType($absolutePath) ?: 'image/jpeg';
        $bytes = File::get($absolutePath);

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => 'data:'.$mime.';base64,'.base64_encode($bytes),
            ],
        ];
    }

    private function extractModerationText(array $response): string
    {
        $content = data_get($response, 'choices.0.message.content');

        if (is_string($content) && trim($content) !== '') {
            return trim($content);
        }

        if (is_array($content)) {
            $chunks = [];

            foreach ($content as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $chunks[] = trim($item);
                    continue;
                }

                if (is_array($item) && trim((string) ($item['text'] ?? '')) !== '') {
                    $chunks[] = trim((string) $item['text']);
                }
            }

            $text = trim(implode("\n", $chunks));

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function postModerationPrompt(): string
    {
        return <<<'PROMPT'
You are MAHALA's post safety moderator for Bosnia and Herzegovina local community posts.

Return strict JSON only: {"allowed": boolean, "reason": string}.

Allow:
- Ordinary local talk, questions, complaints, jokes, sarcasm, gossip, events, wedding/party scenes, and heated but non-threatening disagreement.
- People who are dressed provocatively, tastefully revealing, swimwear, nightlife outfits, or very skimpy clothing if no genitals, nipples, explicit sex act, or full nudity is visible.
- Mild slang and non-targeted frustration.

Reject:
- Full nudity, exposed genitals, exposed nipples, explicit sex acts, sexual content involving minors, sexual coercion, or non-consensual intimate imagery.
- Direct threats, calls for violence, instructions for harm, doxxing, stalking, blackmail, or credible harassment.
- Hate or nationalism targeting ethnicity, nationality, religion, race, gender, sexuality, disability, or similar protected groups.
- Severe profanity aimed at a person or group, dehumanizing insults, or abusive slurs.
- Illegal sales or instructions for weapons, hard drugs, fraud, or other serious crime.

If rejecting, write a short Bosnian reason suitable for showing to the user. If allowed, reason can be "OK".
PROMPT;
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
