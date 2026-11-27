<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SocialPhotoPublisher
{
    public function facebook(string $photo, string $caption): array
    {
        $page = config('services.social.page_id');

        try {
            $response = $this->graph()
                ->attach('source', $photo, 'mahala.jpg', ['Content-Type' => 'image/jpeg'])
                ->post("{$page}/photos", ['caption' => $caption, 'published' => 'true']);
        } catch (ConnectionException) {
            return $this->unconfirmed();
        }

        return $this->publicationResult($response, $response->json('post_id') ?: $response->json('id'));
    }

    public function instagram(string $imageUrl, string $caption): array
    {
        $account = config('services.social.instagram_id');

        try {
            $container = $this->graph()->asForm()->post("{$account}/media", [
                'image_url' => $imageUrl,
                'caption' => $caption,
            ]);
        } catch (ConnectionException) {
            return ['status' => 'failed', 'stage' => 'container'];
        }

        $id = $container->json('id');
        if (! $container->successful() || ! $id) {
            return $this->rejection($container, 'container');
        }

        try {
            $response = $this->graph()->asForm()->post("{$account}/media_publish", ['creation_id' => $id]);
        } catch (ConnectionException) {
            return $this->unconfirmed();
        }

        return $this->publicationResult($response, $response->json('id'));
    }

    private function graph(): PendingRequest
    {
        $version = config('services.social.graph_version');

        return Http::baseUrl("https://graph.facebook.com/{$version}")
            ->withToken(config('services.social.page_token'))
            ->acceptJson()
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(20);
    }

    private function publicationResult(Response $response, mixed $id): array
    {
        if ($response->successful() && $id) {
            return ['status' => 'posted', 'id' => (string) $id];
        }

        if ($response->clientError() && $response->status() !== 408 && $response->json('error.code') !== null) {
            return $this->rejection($response, 'publish');
        }

        return $this->unconfirmed();
    }

    private function rejection(Response $response, string $stage): array
    {
        return [
            'status' => 'failed',
            'stage' => $stage,
            'http_status' => $response->status(),
            'meta_code' => $response->json('error.code'),
            'meta_subcode' => $response->json('error.error_subcode'),
        ];
    }

    private function unconfirmed(): array
    {
        return ['status' => 'unknown', 'stage' => 'publish'];
    }
}
