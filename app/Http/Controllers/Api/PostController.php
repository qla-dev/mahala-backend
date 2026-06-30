<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Mahala;
use App\Models\Post;
use App\Models\PostView;
use App\Models\Topic;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PostController extends Controller
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
            $publishedMahalaIds = $this->publishedMahalaIds($mahalaIds);
            $feedScopeIds = $this->withParentTopicScopes($publishedMahalaIds);
            $page = (int) ($payload['page'] ?? 1);
            $limit = (int) ($payload['limit'] ?? 10);
            $sort = $payload['sort'] ?? 'recent';
            $userId = $request->user('sanctum')?->id;
            $blockedUserIds = $this->blockedUserIds($userId);

            if ($feedScopeIds === []) {
                return response()->json([
                    'data' => [],
                    'meta' => $this->paginationMeta(0, $page, $limit),
                ], 200);
            }

            $engagementWindowStart = Carbon::now()->subDays(10);

            $postsQuery = Post::query()
                ->with(['comments' => fn ($query) => $this->applyAuthorBlockFilter($query->where('status', 1), $blockedUserIds, 'author')->with('authorUser')->withVoteCounts()->latest()])
                ->withVoteCounts()
                ->withCount([
                    'views as views_count',
                    'comments as active_comments_count' => fn ($query) => $this->applyAuthorBlockFilter($query->where('status', 1), $blockedUserIds, 'author'),
                    'comments as recent_comments_count' => fn ($query) => $query
                        ->where('status', 1)
                        ->when($blockedUserIds !== [], fn ($query) => $this->applyAuthorBlockFilter($query, $blockedUserIds, 'author'))
                        ->where('created_at', '>=', $engagementWindowStart),
                    'votes as recent_upvotes_count' => fn ($query) => $query
                        ->where('value', 1)
                        ->where('created_at', '>=', $engagementWindowStart),
                ])
                ->whereIn('mahala_id', $feedScopeIds)
                ->where('status', 1)
                ->where(function ($query) {
                    $query->whereNull('hidden')->orWhere('hidden', false);
                });
            $this->applyFeedSort($postsQuery, $sort);
            $this->applyAuthorBlockFilter($postsQuery, $blockedUserIds, 'author_user_id');

            $paginatedPosts = $postsQuery->paginate($limit, ['*'], 'page', $page);

            $posts = $paginatedPosts
                ->getCollection()
                ->map(fn (Post $post) => $this->formatPost($post, $userId, $blockedUserIds));

            $this->logFeedSortResult(
                'feed',
                $sort,
                $paginatedPosts->currentPage(),
                $paginatedPosts->perPage(),
                $mahalaIds,
                $feedScopeIds,
                $paginatedPosts->getCollection(),
            );

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
            $blockedUserIds = $this->blockedUserIds($userId);
            $publishedMahalaIds = $request->filled('mahala_id')
                ? $this->publishedMahalaIds([(string) $request->query('mahala_id')])
                : null;

            $postsQuery = Post::query()
                ->with(['comments' => fn ($query) => $this->applyAuthorBlockFilter($query->where('status', 1), $blockedUserIds, 'author')->with('authorUser')->withVoteCounts()->latest()])
                ->withVoteCounts()
                ->withCount([
                    'views as views_count',
                    'comments as active_comments_count' => fn ($query) => $this->applyAuthorBlockFilter($query->where('status', 1), $blockedUserIds, 'author'),
                ])
                ->when($request->filled('topic_id'), fn ($query) => $query->where('topic_id', $request->query('topic_id')))
                ->when($request->filled('channel_id'), fn ($query) => $query->where(
                    'topic_id',
                    $this->normalizeTopicId($request->query('channel_id'), $request->query('mahala_id')),
                ))
                ->when(
                    $request->filled('mahala_id'),
                    fn ($query) => $query->whereIn('mahala_id', $publishedMahalaIds),
                    fn ($query) => $query->whereHas('mahala', fn ($mahalaQuery) => $mahalaQuery->where('status', 'published')),
                )
                ->where('status', 1)
                ->latest();
            $this->applyAuthorBlockFilter($postsQuery, $blockedUserIds, 'author_user_id');

            $posts = $postsQuery
                ->get()
                ->map(fn (Post $post) => $this->formatPost($post, $userId, $blockedUserIds));

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
            $attributes['author_user_id'] = $request->user()->id;
            $isInsideMahala = null;

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
                'meta' => [
                    'location_inside_mahala' => $isInsideMahala,
                ],
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
            $userId = $request->user('sanctum')?->id;
            $blockedUserIds = $this->blockedUserIds($userId);
            $postQuery = Post::query()
                ->with(['comments' => fn ($query) => $this->applyAuthorBlockFilter($query->where('status', 1), $blockedUserIds, 'author')->with('authorUser')->withVoteCounts()->latest()])
                ->withVoteCounts()
                ->withCount([
                    'views as views_count',
                    'comments as active_comments_count' => fn ($query) => $this->applyAuthorBlockFilter($query->where('status', 1), $blockedUserIds, 'author'),
                ])
                ->where('id', $id);
            $this->applyAuthorBlockFilter($postQuery, $blockedUserIds, 'author_user_id');
            $post = $postQuery->firstOrFail();

            return response()->json([
                'data' => $this->formatPost($post, $userId, $blockedUserIds),
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

    public function view(Request $request, Post $post)
    {
        $userId = $request->user()->id;

        PostView::query()->firstOrCreate([
            'post_id' => $post->id,
            'user_id' => $userId,
        ]);

        return response()->json([
            'message' => 'Pregled objave je uspjesno sacuvan.',
            'data' => [
                'post_id' => $post->id,
                'views_count' => $post->views()->count(),
            ],
        ]);
    }

    public function retry(Request $request, Post $post)
    {
        try {
            if ((int) $post->author_user_id !== (int) $request->user()->id) {
                return response()->json([
                    'message' => 'Nemate dozvolu za ovu objavu.',
                ], 403);
            }

            $attributes = [
                'topic_id' => $post->topic_id,
                'mahala_id' => $post->mahala_id,
                'content' => $post->content,
            ];

            $this->postAiCheck($attributes, $post->image_uri);

            $post->status = 1;
            $post->hidden = false;
            $post->save();

            return response()->json([
                'message' => 'Objava je ponovo provjerena i objavljena.',
                'data' => $this->formatPost($post->fresh(), $request->user()->id),
                'meta' => [
                    'location_inside_mahala' => null,
                ],
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri ponovnom pokusaju objave.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, Post $post)
    {
        try {
            if ((int) $post->author_user_id !== (int) $request->user()->id) {
                return response()->json([
                    'message' => 'Nemate dozvolu za brisanje ove objave.',
                ], 403);
            }

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
            'image' => [
                'sometimes',
                'nullable',
                'file',
                'max:51200',
                $this->uploadedImageRule(),
            ],
            'is_anonymous' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'integer'],
            'hidden' => ['sometimes', 'nullable', 'boolean'],
            'user_latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'user_longitude' => ['sometimes', 'numeric', 'between:-180,180'],
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
                'content' => ['AI provjera trenutno nije dostupna. Pokušaj ponovo kasnije.'],
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

        $requestPayload = [
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
                        'required' => ['allowed', 'reason', 'rotation_degrees'],
                        'properties' => [
                            'allowed' => ['type' => 'boolean'],
                            'reason' => ['type' => 'string'],
                            'rotation_degrees' => [
                                'type' => 'integer',
                                'enum' => [0, 90, 180, 270],
                            ],
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
        ];

        $response = $client->post($baseUrl.'/chat/completions', $requestPayload);

        if (!$response->successful() && $this->shouldRetryModerationWithoutJsonSchema($response->status(), $response->body())) {
            Log::warning('[MAHALA][post-ai] retrying moderation without json_schema response format', [
                'status' => $response->status(),
                'model' => $model,
                'has_image' => $hasImage,
                'body' => Str::limit($response->body(), 2000),
            ]);

            unset($requestPayload['provider']);
            $requestPayload['response_format'] = [
                'type' => 'json_object',
            ];

            $response = $client->post($baseUrl.'/chat/completions', $requestPayload);
        }

        if (!$response->successful()) {
            Log::warning('[MAHALA][post-ai] OpenRouter moderation request failed', [
                'status' => $response->status(),
                'model' => $model,
                'has_image' => $hasImage,
                'body' => Str::limit($response->body(), 2000),
            ]);

            throw ValidationException::withMessages([
                'content' => ['AI provjera nije uspjela. Pokušaj ponovo kasnije.'],
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
                'content' => ['AI provjera nije vratila validan rezultat. Pokušaj ponovo kasnije.'],
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

        if ($hasImage) {
            $rotationDegrees = (int) ($payload['rotation_degrees'] ?? 0);

            if (in_array($rotationDegrees, [90, 180, 270], true)) {
                $this->rotateStoredImage($imageUri, $rotationDegrees);
            }
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

    private function shouldRetryModerationWithoutJsonSchema(int $status, string $body): bool
    {
        if ($status !== 400) {
            return false;
        }

        return str_contains($body, 'response_json_schema')
            || str_contains($body, 'response_schema')
            || str_contains($body, 'schema at top-level requires')
            || str_contains($body, 'INVALID_ARGUMENT');
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

Return strict JSON only: {"allowed": boolean, "reason": string, "rotation_degrees": 0|90|180|270}.

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

For image posts, also decide whether the stored image is clearly badly rotated.
- rotation_degrees is clockwise degrees to fix the image.
- Use 0 unless the image is clearly sideways or upside down.
- If a landscape image appears intentionally landscape by composition, horizon, subject placement, or scene type, return 0.
- Do not rotate for artistic angles, tilted phones, diagonal composition, or ambiguous cases.
- Only use 90, 180, or 270 when the correction is obvious.
For text-only posts, rotation_degrees must be 0.
PROMPT;
    }

    private function rotateStoredImage(?string $imageUri, int $clockwiseDegrees): void
    {
        if (!$imageUri || !in_array($clockwiseDegrees, [90, 180, 270], true)) {
            return;
        }

        $path = parse_url($imageUri, PHP_URL_PATH);

        if (!$path || !str_starts_with($path, '/uploads/posts/')) {
            return;
        }

        $absolutePath = public_path(ltrim($path, '/'));

        if (!File::exists($absolutePath)) {
            return;
        }

        $source = @imagecreatefromjpeg($absolutePath);

        if (!$source) {
            Log::warning('[MAHALA][post-ai] could not rotate image because stored file is not readable JPEG', [
                'image_uri' => $imageUri,
                'rotation_degrees' => $clockwiseDegrees,
            ]);

            return;
        }

        $rotated = imagerotate($source, 360 - $clockwiseDegrees, 0);
        imagedestroy($source);

        if (!$rotated) {
            Log::warning('[MAHALA][post-ai] image rotation failed', [
                'image_uri' => $imageUri,
                'rotation_degrees' => $clockwiseDegrees,
            ]);

            return;
        }

        ob_start();
        imagejpeg($rotated, null, 82);
        $encoded = ob_get_clean();
        imagedestroy($rotated);

        if (is_string($encoded) && $encoded !== '') {
            File::put($absolutePath, $encoded);
        }
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

        $realPath = $file->getRealPath();
        $source = @imagecreatefromstring(File::get($realPath));

        if (!$source) {
            if ($this->isHeicUpload($file)) {
                throw ValidationException::withMessages([
                    'image' => ['HEIC slika nije konvertovana. Pokusaj ponovo ili izaberi JPEG sliku.'],
                ]);
            }

            throw ValidationException::withMessages([
                'image' => ['Slika nije podrzana ili je ostecena.'],
            ]);
        }

        [$sourceWidth, $sourceHeight] = getimagesize($realPath) ?: [0, 0];
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
        $absolutePath = $directory.DIRECTORY_SEPARATOR.$filename;
        File::put($absolutePath, $encoded);
        $storedUri = '/'.$relativeDirectory.'/'.$filename;

        $this->deleteStoredImage($oldImageUri);

        return $storedUri;
    }

    private function uploadedImageRule(): callable
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (!$value instanceof UploadedFile) {
                return;
            }

            $extension = Str::lower((string) $value->getClientOriginalExtension());
            $mimeType = Str::lower((string) $value->getMimeType());
            $allowedExtensions = ['jpeg', 'jpg', 'png', 'webp', 'heic', 'heif'];
            $allowedMimeTypes = [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/heic',
                'image/heif',
                'image/heic-sequence',
                'image/heif-sequence',
                'application/octet-stream',
            ];

            if (!in_array($extension, $allowedExtensions, true) && !in_array($mimeType, $allowedMimeTypes, true)) {
                $fail('Uploadovana datoteka mora biti slika.');
            }
        };
    }

    private function isHeicUpload(UploadedFile $file): bool
    {
        $extension = Str::lower((string) $file->getClientOriginalExtension());
        $mimeType = Str::lower((string) $file->getMimeType());
        $originalName = Str::lower((string) $file->getClientOriginalName());

        return in_array($extension, ['heic', 'heif'], true)
            || str_contains($mimeType, 'heic')
            || str_contains($mimeType, 'heif')
            || str_ends_with($originalName, '.heic')
            || str_ends_with($originalName, '.heif');
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

    private function applyFeedSort($query, string $sort)
    {
        if ($sort === 'popular') {
            return $query
                ->orderByDesc('recent_upvotes_count')
                ->orderByDesc('created_at')
                ->orderByDesc('id');
        }

        if ($sort === 'commented') {
            return $query
                ->orderByDesc('recent_comments_count')
                ->orderByDesc('created_at')
                ->orderByDesc('id');
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function logFeedSortResult(
        string $surface,
        string $sort,
        int $page,
        int $limit,
        array $requestedMahalaIds,
        array $scopeIds,
        $posts,
    ): void {
        Log::info('[MAHALA_FEED_SORT] '.$surface, [
            'sort' => $sort,
            'page' => $page,
            'limit' => $limit,
            'requested_mahala_ids' => $requestedMahalaIds,
            'scope_ids' => $scopeIds,
            'rows' => $posts->values()->map(fn (Post $post, int $index) => [
                'index' => $index,
                'id' => $post->id,
                'recent_upvotes_count' => (int) ($post->recent_upvotes_count ?? 0),
                'recent_comments_count' => (int) ($post->recent_comments_count ?? 0),
                'active_comments_count' => (int) ($post->active_comments_count ?? 0),
                'upvotes_count' => (int) ($post->upvotes_count ?? 0),
                'downvotes_count' => (int) ($post->downvotes_count ?? 0),
                'created_at' => optional($post->created_at)->toDateTimeString(),
            ])->all(),
        ]);
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

    private function isCoordinateInsideMahalaId(?string $mahalaId, float $latitude, float $longitude): ?bool
    {
        $targetMahalaId = $mahalaId !== null ? (string) $mahalaId : '';

        if ($targetMahalaId === '' || !is_finite($latitude) || !is_finite($longitude)) {
            return false;
        }

        if ($targetMahalaId === self::SARAJEVO_TOPIC_SCOPE_ID) {
            $sarajevoMahalas = Mahala::query()
                ->whereIn('id', self::SARAJEVO_POLYGON_IDS)
                ->get();

            return $sarajevoMahalas->isNotEmpty()
                ? $sarajevoMahalas->contains(fn (Mahala $mahala) => $this->isCoordinateInsideMahala($mahala, $latitude, $longitude))
                : null;
        }

        $mahala = Mahala::query()
            ->where('id', $targetMahalaId)
            ->first();

        if ($mahala) {
            return $this->isCoordinateInsideMahala($mahala, $latitude, $longitude);
        }

        return $this->isExternalMahalaScope($targetMahalaId) ? null : false;
    }

    private function isExternalMahalaScope(string $mahalaId): bool
    {
        return $mahalaId === self::SARAJEVO_TOPIC_SCOPE_ID
            || in_array($mahalaId, self::SARAJEVO_POLYGON_IDS, true)
            || preg_match('/^\d{5}(?:-\d+)?$/', $mahalaId) === 1
            || preg_match('/^(?:BA|HR|RS)(?:\d+)?(?:-\d+)?$/i', $mahalaId) === 1
            || preg_match('/^(?:BA|HR|RS|BIH|border|full|country|region|city|municipality|opcina|općina|sarajevo-arena)[:_-]/i', $mahalaId) === 1;
    }

    private function isCoordinateInsideMahala(Mahala $mahala, float $latitude, float $longitude): bool
    {
        $coordinates = $this->normalizePolygonCoordinates($mahala->coordinates);

        if (count($coordinates) < 3 || !$this->pointInPolygon($latitude, $longitude, $coordinates)) {
            return false;
        }

        $holes = is_array($mahala->holes) ? $mahala->holes : [];

        foreach ($holes as $hole) {
            $holeCoordinates = $this->normalizePolygonCoordinates($hole);

            if (count($holeCoordinates) >= 3 && $this->pointInPolygon($latitude, $longitude, $holeCoordinates)) {
                return false;
            }
        }

        return true;
    }

    private function normalizePolygonCoordinates(mixed $coordinates): array
    {
        if (!is_array($coordinates)) {
            return [];
        }

        return collect($coordinates)
            ->map(function ($coordinate) {
                if (!is_array($coordinate) || !array_key_exists('latitude', $coordinate) || !array_key_exists('longitude', $coordinate)) {
                    return null;
                }

                $latitude = filter_var($coordinate['latitude'], FILTER_VALIDATE_FLOAT);
                $longitude = filter_var($coordinate['longitude'], FILTER_VALIDATE_FLOAT);

                return $latitude !== false && $longitude !== false
                    ? ['latitude' => $latitude, 'longitude' => $longitude]
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function pointInPolygon(float $latitude, float $longitude, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $current = $polygon[$i];
            $previous = $polygon[$j];
            $currentLatitude = (float) $current['latitude'];
            $currentLongitude = (float) $current['longitude'];
            $previousLatitude = (float) $previous['latitude'];
            $previousLongitude = (float) $previous['longitude'];
            $intersects = (($currentLatitude > $latitude) !== ($previousLatitude > $latitude))
                && ($longitude < ($previousLongitude - $currentLongitude) * ($latitude - $currentLatitude) / (($previousLatitude - $currentLatitude) ?: 1.0) + $currentLongitude);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private function formatPost(Post $post, ?int $userId = null, array $blockedUserIds = []): array
    {
        $post->loadMissing([
            'author',
            'comments' => fn ($query) => $this->applyAuthorBlockFilter($query->where('status', 1), $blockedUserIds, 'author')->with('authorUser')->withVoteCounts()->latest(),
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
