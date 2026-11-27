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

    private function publish(string $id, string $caption = 'Pozdrav iz mahale')
    {
        $fixture = base_path('tests/Fixtures/social-photo.jpg');

        return $this->withToken(self::TOKEN)->post('/api/social/photos', [
            'image' => new UploadedFile($fixture, 'social-photo.jpg', 'image/jpeg', null, true),
            'caption' => $caption,
            'publication_id' => $id,
        ], ['Accept' => 'application/json']);
    }
}
