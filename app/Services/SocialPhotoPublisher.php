<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SocialPhotoPublisher
{
    public function isConfigured(string $channel): bool
    {
        return match ($channel) {
            'facebook' => config('services.social.page_id') && config('services.social.page_token'),
            'instagram' => config('services.social.instagram_id') && config('services.social.page_token'),
            'linkedin' => config('services.social.linkedin_organization_id') && config('services.social.linkedin_access_token'),
        };
    }

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

    public function linkedin(string $photo, string $caption): array
    {
        $author = 'urn:li:organization:'.config('services.social.linkedin_organization_id');
        $stage = 'initialize_upload';

        try {
            $initialization = $this->linkedinApi()->post('images?action=initializeUpload', [
                'initializeUploadRequest' => ['owner' => $author],
            ]);
            $image = $initialization->json('value.image');
            $uploadUrl = $initialization->json('value.uploadUrl');
            if (! $initialization->successful() || ! is_string($image) || ! str_starts_with($image, 'urn:li:image:')
                || ! is_string($uploadUrl) || ! $this->isLinkedinUploadUrl($uploadUrl)) {
                return $this->linkedinRejection($initialization, $stage);
            }

            $stage = 'upload';
            $upload = $this->linkedinApi()->withBody($photo, 'image/jpeg')->put($uploadUrl);
            if (! $upload->successful()) {
                return $this->linkedinRejection($upload, $stage);
            }

            $stage = 'image_processing';
            foreach ([0, 500, 1000, 2000] as $delayMilliseconds) {
                if ($delayMilliseconds > 0) {
                    usleep($delayMilliseconds * 1000);
                }
                $imageResponse = $this->linkedinApi()->timeout(5)->get('images/'.rawurlencode($image));
                $status = $imageResponse->json('status');
                if ($imageResponse->successful() && $status === 'AVAILABLE') {
                    break;
                }
                if (! $imageResponse->successful() || ! in_array($status, ['WAITING_UPLOAD', 'PROCESSING'], true)) {
                    return $this->linkedinRejection($imageResponse, $stage);
                }
            }
            if ($status !== 'AVAILABLE') {
                return ['status' => 'failed', 'stage' => $stage];
            }

            $stage = 'publish';
            $response = $this->linkedinApi()->post('posts', [
                'author' => $author,
                'commentary' => addcslashes($caption, '\\|{}@[]()<>#*_~'),
                'visibility' => 'PUBLIC',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'content' => ['media' => ['id' => $image]],
                'lifecycleState' => 'PUBLISHED',
                'isReshareDisabledByAuthor' => false,
            ]);
        } catch (ConnectionException) {
            return $stage === 'publish' ? $this->unconfirmed() : ['status' => 'failed', 'stage' => $stage];
        }

        $id = $response->header('x-restli-id');
        if ($response->created() && $id !== '') {
            return ['status' => 'posted', 'id' => $id];
        }
        if ($response->clientError() && ! in_array($response->status(), [408, 409], true)
            && (int) $response->json('status') === $response->status()) {
            return $this->linkedinRejection($response, $stage);
        }

        return $this->unconfirmed();
    }

    private function linkedinApi(): PendingRequest
    {
        return Http::baseUrl('https://api.linkedin.com/rest')
            ->withToken(config('services.social.linkedin_access_token'))
            ->withHeaders([
                'Linkedin-Version' => config('services.social.linkedin_version'),
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->acceptJson()
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(20);
    }

    private function isLinkedinUploadUrl(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && in_array($parts['host'] ?? null, ['www.linkedin.com', 'api.linkedin.com'], true)
            && ($parts['port'] ?? 443) === 443
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && str_starts_with($parts['path'] ?? '', '/dms-uploads/');
    }

    private function linkedinRejection(Response $response, string $stage): array
    {
        return [
            'status' => 'failed',
            'stage' => $stage,
            'http_status' => $response->status(),
            'linkedin_code' => $response->json('serviceErrorCode'),
        ];
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
