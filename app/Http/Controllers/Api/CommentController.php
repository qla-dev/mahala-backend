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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    public function index(Request $request, string $post)
    {
        try {
            Post::query()->findOrFail($post);
            $userId = $request->user('sanctum')?->id;

            $comments = Comment::query()
                ->with('authorUser')
                ->withVoteCounts()
                ->where('post_id', $post)
                ->where('status', 1)
                ->latest()
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

        $response = $client->post($baseUrl.'/chat/completions', [
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
        ]);

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
            'notifications_app' => true,
            'notifications' => true,
            'notifications_app_location' => true,
            'notifications_app_comments' => true,
            'notifications_app_votes' => true,
            'notifications_location' => true,
            'notifications_comments' => true,
            'notifications_votes' => true,
            'locale' => 'bs',
            'pro_status' => 0,
        ]);

        if (!$settings?->notifications_app || !$settings->notifications_app_comments) {
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
            'notifications_app' => true,
            'notifications' => true,
            'notifications_app_location' => true,
            'notifications_app_comments' => true,
            'notifications_app_votes' => true,
            'notifications_location' => true,
            'notifications_comments' => true,
            'notifications_votes' => true,
            'locale' => 'bs',
            'pro_status' => 0,
        ]);

        if (!$settings?->notifications_app || !$settings->notifications_app_comments) {
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
}
