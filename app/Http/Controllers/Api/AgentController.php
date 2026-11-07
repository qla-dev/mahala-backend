<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mahala;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Agent API (docs/agent-api.md): lets an automated agent post and comment as
 * one of the allow-listed registered users, through the same code paths as
 * the app (validation, image compression, AI moderation, notifications).
 *
 * Every request carries the agent secret, in the X-Agent-Secret header or a
 * "secret" field. Only its bcrypt hash is on the server
 * (services.agent.secret_hash). "as" names the user (username or id), which
 * must be listed in services.agent.users.
 */
class AgentController extends Controller
{
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    public function context(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        return response()->json([
            'users' => User::query()
                ->whereIn('username', $this->allowedUsernames())
                ->get(['id', 'username'])
                ->map(fn (User $user) => ['id' => $user->id, 'username' => $user->username]),
            'mahalas' => Mahala::query()->orderBy('name')->get(['id', 'name', 'level'])->values(),
            'topics' => Topic::query()->orderBy('name')->get(['id', 'slug', 'name', 'mahala_id'])->values(),
            'recentPosts' => Post::query()
                ->latest()
                ->limit(30)
                ->get(['id', 'topic_id', 'mahala_id', 'author_user_id', 'content', 'image_uri', 'status', 'created_at']),
        ]);
    }

    public function storePost(Request $request, PostController $posts)
    {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        if ($error = $this->attachImage($request)) {
            return response()->json(['message' => $error, 'errors' => ['image' => [$error]]], 422);
        }

        return $posts->store($request);
    }

    public function storeComment(Request $request, string $post, CommentController $comments)
    {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        return $comments->store($request, $post);
    }

    /**
     * Checks the secret and acts as the requested user for the rest of the
     * request. Returns an error response, or null when authorized.
     */
    private function denyUnlessAuthorized(Request $request): ?JsonResponse
    {
        $secret = (string) ($request->header('X-Agent-Secret') ?: $request->input('secret', ''));
        $hash = (string) config('services.agent.secret_hash');

        if ($secret === '' || $hash === '' || ! Hash::check($secret, $hash)) {
            return response()->json(['message' => 'Neispravan ili nedostaje agent secret — ništa nije objavljeno.'], 403);
        }

        // Context only needs the secret.
        if ($request->routeIs('agent.context')) {
            return null;
        }

        $as = trim((string) $request->input('as', ''));

        if ($as === '') {
            return response()->json(['message' => 'Nedostaje "as": korisničko ime ili id korisnika.'], 422);
        }

        $user = ctype_digit($as)
            ? User::query()->find((int) $as)
            : User::query()->where('username', ltrim($as, '@'))->first();

        if (! $user || ! in_array($user->username, $this->allowedUsernames(), true)) {
            return response()->json(['message' => 'Agent ne smije objavljivati kao ovaj korisnik.'], 403);
        }

        if ($user->suspended_at) {
            return response()->json(['message' => 'Račun je suspendovan zbog kršenja pravila zajednice.'], 403);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        // Never pass the secret or the impersonation fields on.
        foreach (['secret', 'as', 'author_user_id', 'author'] as $key) {
            $this->removeInput($request, $key);
        }

        return null;
    }

    /** Removes a field from both form and JSON input. */
    private function removeInput(Request $request, string $key): void
    {
        $request->request->remove($key);

        if ($request->isJson()) {
            $request->json()->remove($key);
        }
    }

    /** @return string[] */
    private function allowedUsernames(): array
    {
        return array_values(array_filter(array_map(
            fn ($name) => ltrim(trim((string) $name), '@'),
            (array) config('services.agent.users', []),
        )));
    }

    /**
     * Accepts the post image as a multipart "image" file (left as is), a
     * "data:image/...;base64,..." URL, or an https:// link, and hands it to
     * PostController as an uploaded file so the normal compression applies.
     * Returns an error message, or null.
     */
    private function attachImage(Request $request): ?string
    {
        // Check the raw file bag: hasFile() would cache the (still empty)
        // file list and hide the file attached below.
        if ($request->files->has('image')) {
            return null;
        }

        $value = $request->input('image');
        $this->removeInput($request, 'image');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return 'Slika mora biti datoteka, data:image URL ili https:// link.';
        }

        if (preg_match('#^data:image/(png|jpe?g|webp|heic|heif);base64,(.+)$#is', $value, $match)) {
            $bytes = base64_decode($match[2], true);
            $extension = strtolower($match[1]) === 'jpg' ? 'jpeg' : strtolower($match[1]);
        } elseif (str_starts_with($value, 'https://')) {
            $response = Http::timeout(20)->get($value);

            if (! $response->successful()) {
                return 'Slika s linka nije preuzeta (HTTP '.$response->status().').';
            }

            $bytes = $response->body();
            $extension = 'jpeg';
        } else {
            return 'Slika mora biti datoteka, data:image URL ili https:// link.';
        }

        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return 'Slika je prazna, neispravna ili veća od 8 MB.';
        }

        $path = tempnam(sys_get_temp_dir(), 'agent-image-');
        file_put_contents($path, $bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/'.$extension;

        if (! str_starts_with($mime, 'image/')) {
            @unlink($path);

            return 'Preuzeta datoteka nije slika.';
        }

        $request->files->set('image', new UploadedFile(
            $path,
            'agent-'.Str::random(8).'.'.Str::after($mime, 'image/'),
            $mime,
            null,
            true,
        ));

        return null;
    }
}
