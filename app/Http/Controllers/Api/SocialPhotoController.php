<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SocialPhotoPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SocialPhotoController extends Controller
{
    public function publish(Request $request, SocialPhotoPublisher $publisher): JsonResponse
    {
        $secretHash = (string) config('services.social.publish_token_hash');
        abort_if($secretHash === '', 503, 'Social publishing has not been enabled.');
        abort_unless(hash_equals($secretHash, hash('sha256', (string) $request->bearerToken())), 401);

        $data = $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg', 'max:8192', 'dimensions:min_width=320,max_width=1440'],
            'caption' => ['required', 'string', 'max:2200'],
            'channels' => ['sometimes', 'array', 'list', 'min:1', 'max:2'],
            'channels.*' => ['required', 'distinct', Rule::in(['facebook', 'instagram'])],
            'publication_id' => ['required', 'uuid'],
        ]);

        [$width, $height] = $data['image']->dimensions();
        $ratio = $width / $height;
        if ($ratio < 0.8 || $ratio > 1.91) {
            throw ValidationException::withMessages(['image' => 'The image must have a width-to-height ratio between 0.8 and 1.91.']);
        }

        $channels = $data['channels'] ?? ['facebook', 'instagram'];
        $id = strtolower($data['publication_id']);
        $photo = $data['image']->get();
        $caption = $data['caption'];
        $fingerprint = hash('sha256', hash('sha256', $photo).$caption);
        $cache = Cache::store('file');
        $stateKey = 'social:publication:'.$id;

        $result = $cache->lock($stateKey.':lock', 120)->get(function () use (
            $publisher, $channels, $photo, $caption, $fingerprint, $cache, $stateKey
        ) {
            $state = $cache->get($stateKey, ['fingerprint' => $fingerprint, 'channels' => []]);
            abort_unless($state['fingerprint'] === $fingerprint, 409, 'This publication ID is already tied to another image or caption.');
            $results = [];

            foreach ($channels as $channel) {
                $previous = $state['channels'][$channel] ?? null;
                if (in_array($previous['status'] ?? null, ['posted', 'unknown'], true)) {
                    $results[$channel] = $previous;

                    continue;
                }

                $account = $channel === 'facebook' ? 'page_id' : 'instagram_id';
                if (! config("services.social.{$account}") || ! config('services.social.page_token')) {
                    $results[$channel] = ['status' => 'unconfigured'];

                    continue;
                }

                $mediaId = (string) Str::uuid();
                $mediaKey = 'social:image:'.$mediaId;
                $imageUrl = null;
                if ($channel === 'instagram') {
                    $expires = now()->addMinutes(5);
                    $imageUrl = URL::temporarySignedRoute('social.photo.image', $expires, ['image' => $mediaId]);
                    abort_unless(! app()->isProduction() || str_starts_with($imageUrl, 'https://'), 503, 'Instagram requires an HTTPS media URL.');
                    abort_unless($cache->put($mediaKey, $photo, $expires), 503, 'Could not stage the photo.');
                }

                try {
                    // Reserve the channel before sending a write we cannot safely repeat after a timeout.
                    $state['channels'][$channel] = ['status' => 'unknown', 'stage' => 'publish'];
                    abort_unless($cache->put($stateKey, $state, now()->addDays(7)), 503, 'Could not reserve the publication.');
                    $results[$channel] = $channel === 'facebook'
                        ? $publisher->facebook($photo, $caption)
                        : $publisher->instagram($imageUrl, $caption);
                    $state['channels'][$channel] = $results[$channel];
                    abort_unless($cache->put($stateKey, $state, now()->addDays(7)), 503, 'Could not save the publication result.');
                } finally {
                    if ($imageUrl !== null) {
                        $cache->forget($mediaKey);
                    }
                }
            }

            return $results;
        });

        abort_if($result === false, 409, 'This publication is already in progress.');

        return response()->json(['publication_id' => $id, 'channels' => $result]);
    }

    public function image(string $image): Response
    {
        $photo = Cache::store('file')->get('social:image:'.$image);
        abort_unless(is_string($photo), 404);

        return response($photo)->withHeaders([
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
