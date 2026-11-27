<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as MetaRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class SocialPhotoTest extends TestCase
{
    private const TOKEN = 'test-social-publish-token';

    private const LINKEDIN_UPLOAD_URL = 'https://www.linkedin.com/dms-uploads/image/uploaded-image/0?sync=0';

    private const LINKEDIN_IMAGE = 'urn:li:image:test-image';

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePath = sys_get_temp_dir().'/mahala-social-test-'.Str::uuid();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.stores.file.path' => $this->cachePath,
            'cache.stores.file.lock_path' => $this->cachePath,
            'services.social.publish_token_hash' => hash('sha256', self::TOKEN),
            'services.social.page_id' => '101',
            'services.social.instagram_id' => '202',
            'services.social.page_token' => 'test-page-token',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);

        parent::tearDown();
    }

    public function test_unauthenticated_request_cannot_publish(): void
    {
        $this->post('/api/social/photos', [], ['Accept' => 'application/json'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_it_publishes_standalone_photos_and_retries_only_the_failed_channel(): void
    {
        $imageUrl = null;
        Http::fake([
            'graph.facebook.com/*/101/photos' => Http::response(['id' => 'facebook-photo', 'post_id' => 'facebook-post']),
            'graph.facebook.com/*/202/media' => function (MetaRequest $request) use (&$imageUrl) {
                $imageUrl = $request['image_url'];
                $this->assertTrue(URL::hasValidSignature(Request::create($imageUrl)));
                $this->assertSame('Pozdrav iz mahale', $request['caption']);

                return Http::response(['id' => 'container']);
            },
            'graph.facebook.com/*/202/media_publish' => Http::sequence()
                ->push(['error' => ['code' => 190]], 400)
                ->push(['id' => 'instagram-post']),
        ]);

        $id = (string) Str::uuid();
        $this->publish($id)
            ->assertOk()
            ->assertJsonPath('channels.facebook.id', 'facebook-post')
            ->assertJsonPath('channels.instagram.status', 'failed');
        $this->get($imageUrl)->assertNotFound();
        $this->get('/api/social/photos/image/'.Str::uuid().'.jpg')->assertForbidden();

        $this->publish($id)
            ->assertOk()
            ->assertJsonPath('channels.facebook.id', 'facebook-post')
            ->assertJsonPath('channels.instagram.id', 'instagram-post');
        $this->publish($id)->assertOk();
        $this->publish($id, 'Drugi opis')->assertConflict();
        Http::assertSentCount(5);
    }

    public function test_it_does_not_repeat_an_unconfirmed_facebook_write(): void
    {
        Http::fake([
            'graph.facebook.com/*/101/photos' => Http::response([], 503),
            'graph.facebook.com/*/202/media' => Http::response(['id' => 'container']),
            'graph.facebook.com/*/202/media_publish' => Http::response(['id' => 'instagram-post']),
        ]);

        $id = (string) Str::uuid();
        $this->publish($id)->assertOk()->assertJsonPath('channels.facebook.status', 'unknown');
        $this->publish($id)->assertOk()->assertJsonPath('channels.facebook.status', 'unknown');
        Http::assertSentCount(3);
    }

    public function test_linkedin_requires_its_own_configuration(): void
    {
        config([
            'services.social.linkedin_organization_id' => null,
            'services.social.linkedin_access_token' => null,
        ]);

        $this->publish((string) Str::uuid(), channels: ['linkedin'])
            ->assertOk()
            ->assertJsonPath('channels.linkedin.status', 'unconfigured');
        Http::assertNothingSent();
    }

    public function test_linkedin_can_publish_alongside_meta_and_skips_successful_retries(): void
    {
        $this->fakeLinkedin(Http::response('', 201, ['x-restli-id' => 'urn:li:share:123']));
        Http::fake([
            'graph.facebook.com/*/101/photos' => Http::response(['id' => 'facebook-post']),
            'graph.facebook.com/*/202/media' => Http::response(['id' => 'container']),
            'graph.facebook.com/*/202/media_publish' => Http::response(['id' => 'instagram-post']),
        ]);

        $caption = 'Pozdrav iz mahale (novosti) #mahala';
        $id = (string) Str::uuid();
        $this->publish($id, $caption)->assertOk()->assertJsonMissingPath('channels.linkedin');
        $this->publish($id, $caption, ['facebook', 'instagram', 'linkedin'])
            ->assertOk()
            ->assertJsonPath('channels.facebook.id', 'facebook-post')
            ->assertJsonPath('channels.instagram.id', 'instagram-post')
            ->assertJsonPath('channels.linkedin.id', 'urn:li:share:123');
        $this->publish($id, $caption, ['linkedin'])->assertOk()->assertJsonPath('channels.linkedin.status', 'posted');

        Http::assertSent(function (MetaRequest $request) {
            return $request->url() === 'https://api.linkedin.com/rest/posts'
                && $request->hasHeader('Linkedin-Version', '202611')
                && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
                && $request['author'] === 'urn:li:organization:303'
                && $request['commentary'] === 'Pozdrav iz mahale \(novosti\) \#mahala'
                && $request['content']['media']['id'] === self::LINKEDIN_IMAGE;
        });
        Http::assertSentCount(7);
    }

    public function test_it_does_not_repeat_an_unconfirmed_linkedin_write(): void
    {
        $this->fakeLinkedin(Http::response([], 503));

        $id = (string) Str::uuid();
        $this->publish($id, channels: ['linkedin'])->assertOk()->assertJsonPath('channels.linkedin.status', 'unknown');
        $this->publish($id, channels: ['linkedin'])->assertOk()->assertJsonPath('channels.linkedin.status', 'unknown');
        Http::assertSentCount(4);
    }

    public function test_it_rejects_an_unexpected_linkedin_upload_host_before_sending_the_token(): void
    {
        $this->fakeLinkedin(Http::response(), 'https://example.test/dms-uploads/photo');

        $this->publish((string) Str::uuid(), channels: ['linkedin'])
            ->assertOk()
            ->assertJsonPath('channels.linkedin.status', 'failed')
            ->assertJsonPath('channels.linkedin.stage', 'initialize_upload');
        Http::assertSentCount(1);
    }

    private function fakeLinkedin($postResponse, string $uploadUrl = self::LINKEDIN_UPLOAD_URL): void
    {
        config([
            'services.social.linkedin_organization_id' => '303',
            'services.social.linkedin_access_token' => 'test-linkedin-token',
            'services.social.linkedin_version' => '202611',
        ]);

        Http::fake([
            'https://api.linkedin.com/rest/images?action=initializeUpload' => function (MetaRequest $request) use ($uploadUrl) {
                $this->assertSame('urn:li:organization:303', $request['initializeUploadRequest']['owner']);
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-linkedin-token'));

                return Http::response(['value' => ['image' => self::LINKEDIN_IMAGE, 'uploadUrl' => $uploadUrl]]);
            },
            self::LINKEDIN_UPLOAD_URL => function (MetaRequest $request) {
                $this->assertSame('PUT', $request->method());
                $this->assertTrue($request->hasHeader('Content-Type', 'image/jpeg'));
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-linkedin-token'));
                $this->assertSame(file_get_contents(base_path('tests/Fixtures/social-photo.jpg')), $request->body());

                return Http::response('', 201);
            },
            'https://api.linkedin.com/rest/images/'.rawurlencode(self::LINKEDIN_IMAGE) => Http::response(['status' => 'AVAILABLE']),
            'https://api.linkedin.com/rest/posts' => $postResponse,
        ]);
    }

    private function publish(string $id, string $caption = 'Pozdrav iz mahale', ?array $channels = null)
    {
        $fixture = base_path('tests/Fixtures/social-photo.jpg');

        return $this->withToken(self::TOKEN)->post('/api/social/photos', [
            'image' => new UploadedFile($fixture, 'social-photo.jpg', 'image/jpeg', null, true),
            'caption' => $caption,
            'publication_id' => $id,
            ...($channels === null ? [] : ['channels' => $channels]),
        ], ['Accept' => 'application/json']);
    }
}
