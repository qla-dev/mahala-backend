<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mahala;
use App\Models\Topic;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    private const RESERVED_GENERAL_TOPIC_SLUGS = [
        'glavna',
        'eventi',
        'posao',
        'ljubimci',
        'izgubljeno-i-nadjeno',
        'politika',
        'nocna-smjena',
        'gaming',
        'sport',
        'prodajem-i-kupujem',
        'dating',
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

    public function currentMahalas(Request $request)
    {
        try {
            $payload = $request->validate([
                'mahala_ids' => ['required'],
            ]);

            $mahalaIds = $this->normalizeMahalaIds($payload['mahala_ids']);
            $publishedMahalaIds = $this->publishedMahalaIds($mahalaIds);
            $topicScopeIds = $this->withParentTopicScopes($publishedMahalaIds);

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
                'message' => 'Doslo je do greske pri ucitavanju tema za trenutne mahale.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $publishedMahalaIds = $request->filled('mahala_id')
                ? $this->publishedMahalaIds([(string) $request->query('mahala_id')])
                : null;

            $topics = Topic::query()
                ->when(
                    $request->filled('mahala_id'),
                    fn ($query) => $query->whereIn('mahala_id', $publishedMahalaIds),
                    fn ($query) => $query->whereHas('mahala', fn ($mahalaQuery) => $mahalaQuery->where('status', 'published')),
                )
                ->latest()
                ->get()
                ->map(fn (Topic $topic) => $this->formatTopic($topic));

            return response()->json([
                'data' => $topics,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju tema.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->rules());
            $attributes = $this->buildAttributes($validated);

            $this->topicAiCheck($attributes);

            $topic = Topic::query()->create($attributes);

            return response()->json([
                'message' => 'Tema je uspjesno kreirana.',
                'data' => $this->formatTopic($topic),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri kreiranju teme.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri kreiranju teme.',
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
                'message' => 'Tema nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju teme.',
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
                'message' => 'Tema je uspjesno azurirana.',
                'data' => $this->formatTopic($topic),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Tema nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri azuriranju teme.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri azuriranju teme.',
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
                'message' => 'Tema je uspjesno obrisana.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Tema nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri brisanju teme.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri brisanju teme.',
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
                Rule::notIn(self::RESERVED_GENERAL_TOPIC_SLUGS),
                Rule::unique('topics', 'slug')->ignore($topic?->getKey(), 'id'),
            ]),
            'description' => [$required, 'string'],
            'icon' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
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
            'icon' => $validated['icon'] ?? $topic?->icon ?? $this->resolveTopicIcon($validated['slug'] ?? $topic?->slug ?? null),
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
            'icon' => $this->formatTopicIcon($topic),
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

        while (
            in_array($slug, self::RESERVED_GENERAL_TOPIC_SLUGS, true) ||
            Topic::query()->where('slug', $slug)->exists()
        ) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
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

    private function topicAiCheck(array $attributes): void
    {
        if (!config('services.post_ai_moderation.enabled')) {
            Log::info('[MAHALA][topic-ai] moderation skipped because it is disabled');

            return;
        }

        $apiKey = trim((string) config('services.openrouter.api_key'));

        if ($apiKey === '') {
            Log::warning('[MAHALA][topic-ai] moderation unavailable because OPENROUTER_API_KEY is missing');

            throw ValidationException::withMessages([
                'name' => ['AI provjera trenutno nije dostupna. Pokusaj ponovo kasnije.'],
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
                    'name' => 'mahala_topic_moderation',
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
                    'content' => $this->topicModerationPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'name' => (string) ($attributes['name'] ?? ''),
                        'slug' => (string) ($attributes['slug'] ?? ''),
                        'description' => (string) ($attributes['description'] ?? ''),
                        'mahala_id' => (string) ($attributes['mahala_id'] ?? ''),
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ],
        ]);

        if (!$response->successful()) {
            Log::warning('[MAHALA][topic-ai] OpenRouter moderation request failed', [
                'status' => $response->status(),
                'model' => $model,
                'body' => Str::limit($response->body(), 2000),
            ]);

            throw ValidationException::withMessages([
                'name' => ['AI provjera nije uspjela. Pokusaj ponovo kasnije.'],
            ]);
        }

        $moderationText = $this->extractModerationText($response->json() ?: []);
        $payload = json_decode($moderationText, true);

        if (!is_array($payload) || !array_key_exists('allowed', $payload)) {
            Log::warning('[MAHALA][topic-ai] OpenRouter moderation response was not valid JSON', [
                'model' => $model,
                'content' => Str::limit($moderationText, 2000),
            ]);

            throw ValidationException::withMessages([
                'name' => ['AI provjera nije vratila validan rezultat. Pokusaj ponovo kasnije.'],
            ]);
        }

        if (!$payload['allowed']) {
            Log::info('[MAHALA][topic-ai] topic rejected by moderation', [
                'reason' => Str::limit((string) ($payload['reason'] ?? ''), 500),
                'model' => $model,
            ]);

            throw ValidationException::withMessages([
                'name' => [$payload['reason'] ?: 'Tema nije prosla sigurnosnu provjeru.'],
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

    private function topicModerationPrompt(): string
    {
        return <<<'PROMPT'
You are MAHALA's topic safety moderator for Bosnia and Herzegovina local community topic names.

Return strict JSON only: {"allowed": boolean, "reason": string}.

Allow ordinary local topic ideas, slang, jokes, events, services, buying/selling categories, nightlife, sports, pets, politics, and other broad community discussion categories.

Reject topics whose name, slug, or description promotes or invites:
- Threats, violence, doxxing, stalking, blackmail, or harassment.
- Hate or nationalism targeting ethnicity, nationality, religion, race, gender, sexuality, disability, or similar protected groups.
- Sexual content involving minors, explicit sex acts, non-consensual intimate imagery, or full nudity.
- Illegal sales or instructions for weapons, hard drugs, fraud, or other serious crime.
- Severe profanity aimed at a person or group, dehumanizing insults, or abusive slurs.

If rejecting, write a short Bosnian reason suitable for showing to the user. If allowed, reason can be "OK".
PROMPT;
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

        return Mahala::query()
            ->whereIn('id', $mahalaIds)
            ->where('status', 'published')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
