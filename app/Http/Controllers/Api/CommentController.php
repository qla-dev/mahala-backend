<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Services\ExpoPushNotificationService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    private array $authorRahatlukPointsCache = [];

    public function index(Request $request, string $post)
    {
        try {
            Post::query()->findOrFail($post);
            $userId = $request->user('sanctum')?->id;
            $blockedUserIds = $this->blockedUserIds($userId);

            $commentsQuery = Comment::query()
                ->with('authorUser')
                ->withVoteCounts()
                ->where('post_id', $post)
                ->where('status', 1)
                ->latest();
            $this->applyAuthorBlockFilter($commentsQuery, $blockedUserIds, 'author');

            $comments = $commentsQuery
                ->get()
                ->map(fn (Comment $comment) => $this->formatComment($comment, $userId));

            return response()->json([
                'data' => $comments,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Objava nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju komentara.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request, string $post)
    {
        try {
            $postModel = Post::query()->findOrFail($post);

            $validated = $request->validate([
                'author' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
                'author_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
                'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('comments', 'id')],
                'content' => ['required', 'string'],
                'is_anonymous' => ['sometimes', 'boolean'],
                'status' => ['sometimes', 'integer'],
            ]);

            $parentId = $validated['parent_id'] ?? null;
            $parent = null;

            if ($parentId !== null) {
                $parent = Comment::query()->findOrFail($parentId);

                if ((string) $parent->post_id !== (string) $post || $parent->parent_id !== null) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['Odabrani roditeljski komentar nije ispravan.'],
                    ]);
                }
            }

            $this->commentAiCheck($validated['content'], [
                'post_id' => $post,
                'parent_id' => $parentId,
                'mahala_id' => $postModel->mahala_id,
                'topic_id' => $postModel->topic_id,
            ]);

            $comment = Comment::query()->create([
                'post_id' => $post,
                'parent_id' => $parentId,
                'author' => $validated['author'] ?? $validated['author_user_id'] ?? null,
                'content' => $validated['content'],
                'is_anonymous' => $validated['is_anonymous'] ?? true,
                'status' => $validated['status'] ?? 1,
            ]);
            $comment->load('authorUser');

            if ((int) $comment->status === 1) {
                $this->createCommentNotifications($postModel, $comment, $parent);
            }

            return response()->json([
                'message' => 'Komentar je uspjesno kreiran.',
                'data' => $this->formatComment($comment, $request->user('sanctum')?->id),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Objava nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri kreiranju komentara.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri kreiranju komentara.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function retry(Request $request, Comment $comment)
    {
        try {
            if ((int) $comment->author !== (int) $request->user()->id) {
                return response()->json([
                    'message' => 'Nemate dozvolu za ovaj komentar.',
                ], 403);
            }

            $post = Post::query()->findOrFail($comment->post_id);

            $this->commentAiCheck($comment->content, [
                'post_id' => $post->id,
                'parent_id' => $comment->parent_id,
                'mahala_id' => $post->mahala_id,
                'topic_id' => $post->topic_id,
            ]);

            $comment->status = 1;
            $comment->save();
            $comment->load('authorUser');

            $parent = $comment->parent_id ? Comment::query()->find($comment->parent_id) : null;
            $this->createCommentNotifications($post, $comment, $parent);

            return response()->json([
                'message' => 'Komentar je ponovo provjeren i objavljen.',
                'data' => $this->formatComment($comment->fresh('authorUser'), $request->user()->id),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Komentar nije pronadjen.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri ponovnom pokusaju komentara.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, Comment $comment)
    {
        try {
            if ((int) $comment->author !== (int) $request->user()->id) {
                return response()->json([
                    'message' => 'Nemate dozvolu za ovaj komentar.',
                ], 403);
            }

            Comment::query()
                ->where('parent_id', $comment->id)
                ->delete();
            $comment->delete();

            return response()->json([
                'message' => 'Komentar je uspjesno obrisan.',
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri brisanju komentara.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri brisanju komentara.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function commentAiCheck(string $content, array $context = []): void
    {
        if (!config('services.post_ai_moderation.enabled')) {
            Log::info('[MAHALA][comment-ai] moderation skipped because it is disabled');

            return;
        }

        $apiKey = trim((string) config('services.openrouter.api_key'));

        if ($apiKey === '') {
            Log::warning('[MAHALA][comment-ai] moderation unavailable because OPENROUTER_API_KEY is missing');

            throw ValidationException::withMessages([
                'content' => ['AI provjera trenutno nije dostupna. Pokusaj ponovo kasnije.'],
            ]);
        }

        $model = trim((string) config('services.post_ai_moderation.text_model', 'google/gemini-2.5-flash'));
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

        $requestPayload = [
            'model' => $model,
            'temperature' => 0,
            'provider' => [
                'require_parameters' => true,
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'mahala_comment_moderation',
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
                    'content' => $this->commentModerationPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'content' => $content,
                        'context' => $context,
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ],
        ];

        $response = $client->post($baseUrl.'/chat/completions', $requestPayload);

        if (!$response->successful() && $this->shouldRetryModerationWithoutJsonSchema($response->status(), $response->body())) {
            Log::warning('[MAHALA][comment-ai] retrying moderation without json_schema response format', [
                'status' => $response->status(),
                'model' => $model,
                'body' => Str::limit($response->body(), 2000),
            ]);

            unset($requestPayload['provider']);
            $requestPayload['response_format'] = [
                'type' => 'json_object',
            ];

            $response = $client->post($baseUrl.'/chat/completions', $requestPayload);
        }

        if (!$response->successful()) {
            Log::warning('[MAHALA][comment-ai] OpenRouter moderation request failed', [
                'status' => $response->status(),
                'model' => $model,
                'body' => Str::limit($response->body(), 2000),
            ]);

            throw ValidationException::withMessages([
                'content' => ['AI provjera nije uspjela. Pokusaj ponovo kasnije.'],
            ]);
        }

        $moderationText = $this->extractModerationText($response->json() ?: []);
        $payload = json_decode($moderationText, true);

        if (!is_array($payload) || !array_key_exists('allowed', $payload)) {
            Log::warning('[MAHALA][comment-ai] OpenRouter moderation response was not valid JSON', [
                'model' => $model,
                'content' => Str::limit($moderationText, 2000),
            ]);

            throw ValidationException::withMessages([
                'content' => ['AI provjera nije vratila validan rezultat. Pokusaj ponovo kasnije.'],
            ]);
        }

        if (!$payload['allowed']) {
            Log::info('[MAHALA][comment-ai] comment rejected by moderation', [
                'reason' => Str::limit((string) ($payload['reason'] ?? ''), 500),
                'model' => $model,
            ]);

            throw ValidationException::withMessages([
                'content' => [$payload['reason'] ?: 'Komentar nije prosao sigurnosnu provjeru.'],
            ]);
        }
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

    private function commentModerationPrompt(): string
    {
        return <<<'PROMPT'
You are MAHALA's comment safety moderator for Bosnia and Herzegovina local community replies.

Return strict JSON only: {"allowed": boolean, "reason": string}.

Allow:
- Ordinary replies, local disagreement, jokes, sarcasm, gossip, and mild slang.
- Heated but non-threatening conversation.

Reject:
- Direct threats, calls for violence, instructions for harm, doxxing, stalking, blackmail, or credible harassment.
- Hate or nationalism targeting ethnicity, nationality, religion, race, gender, sexuality, disability, or similar protected groups.
- Severe profanity aimed at a person or group, dehumanizing insults, or abusive slurs.
- Explicit sexual content involving minors, sexual coercion, or non-consensual intimate content.
- Illegal sales or instructions for weapons, hard drugs, fraud, or other serious crime.

If rejecting, write a short Bosnian reason suitable for showing to the user. If allowed, reason can be "OK".
PROMPT;
    }

    private function createCommentNotifications(Post $post, Comment $comment, ?Comment $parent): void
    {
        $this->createCommentReplyNotification($post, $comment, $parent);

        if ($parent && $post->author_user_id && (string) $post->author_user_id === (string) $parent->author) {
            return;
        }

        $this->createPostCommentNotification($post, $comment);
    }

    private function createPostCommentNotification(Post $post, Comment $comment): void
    {
        if (!$post->author_user_id || (string) $post->author_user_id === (string) $comment->author) {
            return;
        }

        $settings = $post->author?->settings()->firstOrCreate([], [
            'notifications_comments' => true,
            'notifications_votes' => true,
            'notifications_location' => true,
            'notifications_startup_mahalas' => true,
            'locale' => 'bs',
            'pro_status' => 0,
        ]);

        if (!$settings?->notifications_comments) {
            return;
        }

        $notification = Notification::query()->create([
            'user_id' => $post->author_user_id,
            'from_user_id' => $comment->is_anonymous ? null : $comment->author,
            'type' => Notification::TYPE_COMMENT,
            'title' => 'comment',
            'body' => 'post_comment',
            'related_post_id' => $post->id,
            'related_comment_id' => $comment->id,
        ]);

        app(ExpoPushNotificationService::class)->sendNotification($notification);
    }

    private function createCommentReplyNotification(Post $post, Comment $comment, ?Comment $parent): void
    {
        if (!$parent?->author || (string) $parent->author === (string) $comment->author) {
            return;
        }

        $settings = $parent->authorUser?->settings()->firstOrCreate([], [
            'notifications_comments' => true,
            'notifications_votes' => true,
            'notifications_location' => true,
            'notifications_startup_mahalas' => true,
            'locale' => 'bs',
            'pro_status' => 0,
        ]);

        if (!$settings?->notifications_comments) {
            return;
        }

        $notification = Notification::query()->create([
            'user_id' => $parent->author,
            'from_user_id' => $comment->is_anonymous ? null : $comment->author,
            'type' => Notification::TYPE_COMMENT_REPLY,
            'title' => 'comment_reply',
            'body' => 'comment_reply',
            'related_post_id' => $post->id,
            'related_comment_id' => $comment->id,
        ]);

        app(ExpoPushNotificationService::class)->sendNotification($notification);
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
