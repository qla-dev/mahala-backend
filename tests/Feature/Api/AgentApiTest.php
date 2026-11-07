<?php

namespace Tests\Feature\Api;

use App\Models\Comment;
use App\Models\Mahala;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-agent-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.agent.secret_hash' => Hash::make(self::SECRET),
            'services.agent.users' => ['agentuser'],
            'services.post_ai_moderation.enabled' => false,
            'services.comment_ai_moderation.enabled' => false,
        ]);
    }

    public function test_rejects_missing_or_wrong_secret(): void
    {
        $agent = User::factory()->create(['username' => 'agentuser']);
        $mahala = $this->createMahala();
        $body = ['as' => $agent->username, 'topic_id' => 'glavna', 'mahala_id' => $mahala->id, 'content' => 'Hej'];

        $this->postJson('/api/agent/posts', $body)->assertForbidden();
        $this->postJson('/api/agent/posts', $body + ['secret' => 'wrong'])->assertForbidden();
        $this->getJson('/api/agent/context', ['X-Agent-Secret' => 'wrong'])->assertForbidden();

        $this->assertSame(0, Post::query()->count());
    }

    public function test_rejects_users_not_on_the_allow_list(): void
    {
        $other = User::factory()->create(['username' => 'someoneelse']);
        $mahala = $this->createMahala();

        $this->postJson('/api/agent/posts', [
            'secret' => self::SECRET,
            'as' => $other->username,
            'topic_id' => 'glavna',
            'mahala_id' => $mahala->id,
            'content' => 'Ne smije',
        ])->assertForbidden();

        $this->assertSame(0, Post::query()->count());
    }

    public function test_posts_as_allowed_user_with_data_url_image(): void
    {
        $agent = User::factory()->create(['username' => 'agentuser']);
        $mahala = $this->createMahala();
        $before = count(File::glob(public_path('uploads/posts/*/*/*.jpg')) ?: []);

        $response = $this->postJson('/api/agent/posts', [
            'as' => '@agentuser',
            'topic_id' => 'glavna',
            'mahala_id' => $mahala->id,
            'content' => 'Objava od agenta',
            'is_anonymous' => false,
            // Spoofed author must be ignored.
            'author_user_id' => 999,
            'image' => 'data:image/png;base64,'.base64_encode($this->pngBytes()),
        ], ['X-Agent-Secret' => self::SECRET]);

        $response->assertCreated();
        $post = Post::query()->sole();
        $this->assertSame($agent->id, (int) $post->author_user_id);
        $this->assertSame('Objava od agenta', $post->content);
        $this->assertNotEmpty($post->image_uri);

        foreach (array_slice(File::glob(public_path('uploads/posts/*/*/*.jpg')) ?: [], $before) as $file) {
            File::delete($file);
        }
    }

    public function test_posts_with_image_from_https_link(): void
    {
        User::factory()->create(['username' => 'agentuser']);
        $mahala = $this->createMahala();
        $before = count(File::glob(public_path('uploads/posts/*/*/*.jpg')) ?: []);
        Http::fake(['https://images.example.com/*' => Http::response($this->pngBytes(), 200, ['Content-Type' => 'image/png'])]);

        $this->postJson('/api/agent/posts', [
            'secret' => self::SECRET,
            'as' => 'agentuser',
            'topic_id' => 'glavna',
            'mahala_id' => $mahala->id,
            'content' => 'Sa linka',
            'image' => 'https://images.example.com/photo.png',
        ])->assertCreated();

        $this->assertNotEmpty(Post::query()->sole()->image_uri);

        foreach (array_slice(File::glob(public_path('uploads/posts/*/*/*.jpg')) ?: [], $before) as $file) {
            File::delete($file);
        }
    }

    public function test_comments_as_allowed_user(): void
    {
        $agent = User::factory()->create(['username' => 'agentuser']);
        $owner = User::factory()->create();
        $mahala = $this->createMahala();
        $post = Post::query()->create([
            'topic_id' => 'glavna',
            'mahala_id' => $mahala->id,
            'author_user_id' => $owner->id,
            'content' => 'Objava',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);

        $this->postJson("/api/agent/posts/{$post->id}/comments", [
            'secret' => self::SECRET,
            'as' => (string) $agent->id,
            'content' => 'Komentar od agenta',
            'is_anonymous' => false,
        ])->assertCreated();

        $comment = Comment::query()->sole();
        $this->assertSame($agent->id, (int) $comment->author);
        $this->assertSame('Komentar od agenta', $comment->content);
    }

    public function test_context_lists_allowed_users(): void
    {
        User::factory()->create(['username' => 'agentuser']);
        User::factory()->create(['username' => 'hidden']);

        $this->getJson('/api/agent/context', ['X-Agent-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.username', 'agentuser');
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 139, 92, 246));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function createMahala(): Mahala
    {
        return Mahala::query()->create([
            'id' => 'user-test-mahala',
            'name' => 'Test Mahala',
            'slug' => 'test-mahala',
            'status' => 'published',
            'privacy' => 0,
            'level' => 2,
            'latitude' => 43.85,
            'longitude' => 18.42,
            'coordinates' => [
                ['latitude' => 43.85, 'longitude' => 18.42],
                ['latitude' => 43.86, 'longitude' => 18.43],
                ['latitude' => 43.84, 'longitude' => 18.44],
            ],
            'holes' => [],
        ]);
    }
}
