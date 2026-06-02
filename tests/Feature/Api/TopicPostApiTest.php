<?php

namespace Tests\Feature\Api;

use App\Models\Mahala;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TopicPostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_and_lists_topics(): void
    {
        $user = User::factory()->create();
        $mahala = $this->createMahala();

        $response = $this->postJson('/api/topics', [
            'mahala_id' => $mahala->id,
            'created_by_user_id' => $user->id,
            'name' => 'Servisne dojave',
            'description' => 'Kvarovi, radovi i korisne servisne informacije',
            'icon' => 'construct',
            'is_premium' => true,
            'is_system' => false,
            'status' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.mahala_id', $mahala->id)
            ->assertJsonPath('data.created_by_user_id', $user->id)
            ->assertJsonPath('data.name', 'Servisne dojave')
            ->assertJsonPath('data.slug', 'servisne-dojave')
            ->assertJsonPath('data.icon', 'construct')
            ->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.status', 1);

        $this->assertDatabaseHas('topics', [
            'id' => $response->json('data.id'),
            'mahala_id' => $mahala->id,
            'slug' => 'servisne-dojave',
        ]);

        $this->getJson('/api/topics')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $response->json('data.id'));
    }

    public function test_it_creates_and_lists_posts(): void
    {
        $user = User::factory()->create();
        $mahala = $this->createMahala();
        $topic = Topic::query()->create([
            'mahala_id' => $mahala->id,
            'created_by_user_id' => $user->id,
            'name' => 'Glavna',
            'slug' => 'glavna',
            'description' => 'Glavni lokalni tok za sve oko tebe',
            'is_premium' => false,
            'is_system' => true,
            'status' => 1,
        ]);

        $response = $this->postJson('/api/posts', [
            'topic_id' => 'glavna',
            'author_user_id' => $user->id,
            'mahala_id' => $mahala->id,
            'content' => 'Ima li ko za kafu?',
            'image_uri' => null,
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.topic_id', 'glavna')
            ->assertJsonPath('data.author_user_id', $user->id)
            ->assertJsonPath('data.mahala_id', $mahala->id)
            ->assertJsonPath('data.content', 'Ima li ko za kafu?')
            ->assertJsonPath('data.color_hex', '#f59e0b')
            ->assertJsonPath('data.is_anonymous', true)
            ->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.hidden', false);

        $this->assertDatabaseHas('posts', [
            'id' => $response->json('data.id'),
            'topic_id' => 'glavna',
            'mahala_id' => $mahala->id,
        ]);

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $response->json('data.id'));
    }

    public function test_it_creates_and_lists_comments_for_a_post(): void
    {
        $user = User::factory()->create();
        $mahala = $this->createMahala();
        $post = \App\Models\Post::query()->create([
            'topic_id' => 'glavna',
            'author_user_id' => $user->id,
            'mahala_id' => $mahala->id,
            'content' => 'Post sa komentarima',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);

        $response = $this->postJson("/api/posts/{$post->id}/comments", [
            'author_user_id' => $user->id,
            'content' => 'Prvi komentar',
            'is_anonymous' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.post_id', $post->id)
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.author_user_id', $user->id)
            ->assertJsonPath('data.author_username', $user->username)
            ->assertJsonPath('data.content', 'Prvi komentar')
            ->assertJsonPath('data.is_anonymous', true)
            ->assertJsonPath('data.status', 1);

        $this->assertDatabaseHas('comments', [
            'id' => $response->json('data.id'),
            'post_id' => $post->id,
            'author' => $user->id,
            'content' => 'Prvi komentar',
            'status' => 1,
        ]);

        $this->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $response->json('data.id'));

        $this->getJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('data.comments_count', 1)
            ->assertJsonPath('data.comments.0.author_user_id', $user->id)
            ->assertJsonPath('data.comments.0.author_username', $user->username)
            ->assertJsonPath('data.comments.0.content', 'Prvi komentar');

        $childResponse = $this->postJson("/api/posts/{$post->id}/comments", [
            'author_user_id' => $user->id,
            'parent_id' => $response->json('data.id'),
            'content' => 'Komentar na komentar',
            'is_anonymous' => false,
        ]);

        $childResponse
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $response->json('data.id'))
            ->assertJsonPath('data.content', 'Komentar na komentar');

        $this->postJson("/api/posts/{$post->id}/comments", [
            'author_user_id' => $user->id,
            'parent_id' => $childResponse->json('data.id'),
            'content' => 'Predubok komentar',
        ])->assertUnprocessable();
    }

    public function test_authenticated_user_can_vote_on_a_post(): void
    {
        $user = User::factory()->create();
        $post = \App\Models\Post::query()->create([
            'topic_id' => 'glavna',
            'content' => 'Post za glasanje',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/posts/{$post->id}/vote", ['value' => 1])
            ->assertOk()
            ->assertJsonPath('data.post_id', $post->id)
            ->assertJsonPath('data.upvotes', 1)
            ->assertJsonPath('data.downvotes', 0)
            ->assertJsonPath('data.my_vote', 1);

        $this->postJson("/api/posts/{$post->id}/vote", ['value' => -1])
            ->assertOk()
            ->assertJsonPath('data.upvotes', 0)
            ->assertJsonPath('data.downvotes', 1)
            ->assertJsonPath('data.my_vote', -1);

        $this->assertDatabaseCount('post_votes', 1);

        $this->postJson("/api/posts/{$post->id}/vote", ['value' => 0])
            ->assertOk()
            ->assertJsonPath('data.upvotes', 0)
            ->assertJsonPath('data.downvotes', 0)
            ->assertJsonPath('data.my_vote', 0);

        $this->assertDatabaseCount('post_votes', 0);
    }

    public function test_authenticated_user_can_vote_on_a_comment(): void
    {
        $user = User::factory()->create();
        $post = \App\Models\Post::query()->create([
            'topic_id' => 'glavna',
            'content' => 'Post sa komentarom za glasanje',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);
        $comment = \App\Models\Comment::query()->create([
            'post_id' => $post->id,
            'author' => $user->id,
            'content' => 'Komentar za glasanje',
            'is_anonymous' => true,
            'status' => 1,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/comments/{$comment->id}/vote", ['value' => 1])
            ->assertOk()
            ->assertJsonPath('data.reply_id', $comment->id)
            ->assertJsonPath('data.upvotes', 1)
            ->assertJsonPath('data.downvotes', 0)
            ->assertJsonPath('data.my_vote', 1);

        $this->getJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('data.comments.0.upvotes', 1)
            ->assertJsonPath('data.comments.0.downvotes', 0)
            ->assertJsonPath('data.comments.0.my_vote', 1);
    }

    public function test_authenticated_user_can_read_and_update_settings(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/user-settings')
            ->assertOk()
            ->assertJsonPath('data.notifications_app', true)
            ->assertJsonPath('data.notifications', true)
            ->assertJsonPath('data.locale', 'bs')
            ->assertJsonPath('data.pro_status', 0);

        $this->patchJson('/api/user-settings', [
            'notifications_app' => false,
            'notifications' => false,
            'locale' => 'bs',
            'pro_status' => 1,
            'pro_started_at' => '2026-06-02 10:00:00',
            'pro_ends_at' => '2026-07-02 10:00:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.notifications_app', false)
            ->assertJsonPath('data.notifications', false)
            ->assertJsonPath('data.pro_status', 1);

        $this->assertDatabaseHas('user_settings', [
            'user_id' => $user->id,
            'notifications_app' => false,
            'notifications' => false,
            'locale' => 'bs',
            'pro_status' => 1,
        ]);
    }

    public function test_authenticated_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable();

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(
            'new-password',
            $user->fresh()->password,
        ));
    }

    public function test_authenticated_user_can_update_username_and_name_but_not_email(): void
    {
        $user = User::factory()->create([
            'username' => 'old_username',
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/profile', [
            'username' => 'new_username',
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('user.username', 'new_username')
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.email', 'old@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'username' => 'new_username',
            'name' => 'New Name',
            'email' => 'old@example.com',
        ]);
    }

    public function test_commenting_on_someones_post_creates_notification(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $post = \App\Models\Post::query()->create([
            'topic_id' => 'glavna',
            'author_user_id' => $owner->id,
            'content' => 'Objava za komentar',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);

        $this->postJson("/api/posts/{$post->id}/comments", [
            'author_user_id' => $commenter->id,
            'content' => 'Novi komentar',
            'is_anonymous' => false,
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'from_user_id' => $commenter->id,
            'type' => 1,
            'related_post_id' => $post->id,
        ]);
    }

    public function test_voting_on_someones_post_creates_notification_when_app_notifications_are_enabled(): void
    {
        $owner = User::factory()->create();
        $voter = User::factory()->create();
        $post = \App\Models\Post::query()->create([
            'topic_id' => 'glavna',
            'author_user_id' => $owner->id,
            'content' => 'Objava za glas',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);

        Sanctum::actingAs($voter);

        $this->postJson("/api/posts/{$post->id}/vote", ['value' => -1])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'from_user_id' => $voter->id,
            'type' => 2,
            'vote_value' => -1,
            'related_post_id' => $post->id,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', 2)
            ->assertJsonPath('data.0.vote_value', -1)
            ->assertJsonPath('data.0.related_post_id', $post->id);
    }

    public function test_authenticated_user_can_bulk_see_notifications(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $otherUser = User::factory()->create();
        $post = \App\Models\Post::query()->create([
            'topic_id' => 'glavna',
            'author_user_id' => $owner->id,
            'content' => 'Objava za notifikacije',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);

        $firstNotification = \App\Models\Notification::query()->create([
            'user_id' => $owner->id,
            'from_user_id' => $actor->id,
            'type' => 1,
            'title' => 'comment',
            'body' => 'post_comment',
            'related_post_id' => $post->id,
        ]);
        $secondNotification = \App\Models\Notification::query()->create([
            'user_id' => $owner->id,
            'from_user_id' => $actor->id,
            'type' => 2,
            'vote_value' => 1,
            'title' => 'vote',
            'body' => 'post_vote',
            'related_post_id' => $post->id,
        ]);
        $otherNotification = \App\Models\Notification::query()->create([
            'user_id' => $otherUser->id,
            'from_user_id' => $actor->id,
            'type' => 2,
            'vote_value' => -1,
            'title' => 'vote',
            'body' => 'post_vote',
            'related_post_id' => $post->id,
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/notifications/bulk-see', [
            'ids' => [$firstNotification->id, $otherNotification->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.seen_count', 1);

        $this->assertNotNull($firstNotification->fresh()->read_at);
        $this->assertNull($secondNotification->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);

        $this->postJson('/api/notifications/bulk-see')
            ->assertOk()
            ->assertJsonPath('data.seen_count', 1);

        $this->assertNotNull($secondNotification->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_it_lists_feed_posts_for_current_mahalas(): void
    {
        $firstMahala = $this->createMahala();
        $secondMahala = Mahala::query()->create([
            'id' => 'user-second-mahala',
            'name' => 'Second Mahala',
            'slug' => 'second-mahala',
            'status' => 'published',
            'privacy' => 0,
            'level' => 2,
            'latitude' => 43.86,
            'longitude' => 18.43,
            'coordinates' => [
                ['latitude' => 43.86, 'longitude' => 18.43],
                ['latitude' => 43.87, 'longitude' => 18.44],
                ['latitude' => 43.85, 'longitude' => 18.45],
            ],
            'holes' => [],
        ]);
        $outsideMahala = Mahala::query()->create([
            'id' => 'user-outside-mahala',
            'name' => 'Outside Mahala',
            'slug' => 'outside-mahala',
            'status' => 'published',
            'privacy' => 0,
            'level' => 2,
            'latitude' => 43.80,
            'longitude' => 18.40,
            'coordinates' => [
                ['latitude' => 43.80, 'longitude' => 18.40],
                ['latitude' => 43.81, 'longitude' => 18.41],
                ['latitude' => 43.79, 'longitude' => 18.42],
            ],
            'holes' => [],
        ]);

        $firstPost = \App\Models\Post::query()->create([
            'topic_id' => 'glavna',
            'mahala_id' => $firstMahala->id,
            'content' => 'First feed post',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);
        \App\Models\Post::query()->create([
            'topic_id' => 'glavna',
            'mahala_id' => $secondMahala->id,
            'content' => 'Second feed post',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);
        \App\Models\Post::query()->create([
            'topic_id' => 'glavna',
            'mahala_id' => $outsideMahala->id,
            'content' => 'Outside feed post',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);

        $this->getJson("/api/feed?mahala_ids={$firstMahala->id},{$secondMahala->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $firstPost->id]);
    }

    public function test_it_paginates_feed_posts_for_current_mahalas(): void
    {
        $mahala = $this->createMahala();

        foreach (range(1, 12) as $index) {
            \App\Models\Post::query()->create([
                'topic_id' => 'glavna',
                'mahala_id' => $mahala->id,
                'content' => "Feed post {$index}",
                'is_anonymous' => true,
                'status' => 1,
                'hidden' => false,
            ]);
        }

        $this->getJson("/api/feed?mahala_ids={$mahala->id}&page=1&limit=10")
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.limit', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.has_more', true);

        $this->getJson("/api/feed?mahala_ids={$mahala->id}&page=2&limit=10")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_loads_startup_topics_and_first_feed_page_for_current_mahalas(): void
    {
        $mahala = $this->createMahala();

        Topic::query()->create([
            'mahala_id' => $mahala->id,
            'name' => 'Glavna',
            'slug' => 'glavna',
            'description' => 'Glavni lokalni tok za sve oko tebe',
            'icon' => 'chatbubble-ellipses',
            'is_system' => true,
        ]);

        foreach (range(1, 12) as $index) {
            \App\Models\Post::query()->create([
                'topic_id' => 'glavna',
                'mahala_id' => $mahala->id,
                'content' => "Startup feed post {$index}",
                'is_anonymous' => true,
                'status' => 1,
                'hidden' => false,
            ]);
        }

        $this->getJson("/api/startup?mahala_ids={$mahala->id}&limit=10")
            ->assertOk()
            ->assertJsonCount(1, 'data.topics')
            ->assertJsonCount(10, 'data.posts')
            ->assertJsonPath('meta.mahala_ids.0', $mahala->id)
            ->assertJsonPath('meta.scope_ids.0', $mahala->id)
            ->assertJsonPath('meta.posts.page', 1)
            ->assertJsonPath('meta.posts.limit', 10)
            ->assertJsonPath('meta.posts.total', 12)
            ->assertJsonPath('meta.posts.last_page', 2)
            ->assertJsonPath('meta.posts.has_more', true);
    }

    public function test_topic_uses_default_icon_when_icon_is_not_supplied(): void
    {
        $mahala = $this->createMahala();

        $response = $this->postJson('/api/topics', [
            'mahala_id' => $mahala->id,
            'name' => 'Servis',
            'description' => 'Lokalni servisni razgovori.',
            'status' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.icon', 'chatbubble-ellipses');
    }

    public function test_feed_post_uses_mahala_color_without_topic_record(): void
    {
        $post = \App\Models\Post::query()->create([
            'topic_id' => 'posao',
            'mahala_id' => '10871',
            'content' => 'General posao post',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);

        $this->getJson('/api/feed?mahala_ids=10871')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $post->id,
                'topic_id' => 'posao',
                'color_hex' => '#2563eb',
            ]);
    }

    public function test_it_lists_topics_for_current_mahalas(): void
    {
        $firstMahala = $this->createMahala();
        $secondMahala = Mahala::query()->create([
            'id' => 'user-second-mahala',
            'name' => 'Second Mahala',
            'slug' => 'second-mahala',
            'status' => 'published',
            'privacy' => 0,
            'level' => 2,
            'latitude' => 43.86,
            'longitude' => 18.43,
            'coordinates' => [
                ['latitude' => 43.86, 'longitude' => 18.43],
                ['latitude' => 43.87, 'longitude' => 18.44],
                ['latitude' => 43.85, 'longitude' => 18.45],
            ],
            'holes' => [],
        ]);
        $outsideMahala = Mahala::query()->create([
            'id' => 'user-outside-mahala',
            'name' => 'Outside Mahala',
            'slug' => 'outside-mahala',
            'status' => 'published',
            'privacy' => 0,
            'level' => 2,
            'latitude' => 43.80,
            'longitude' => 18.40,
            'coordinates' => [
                ['latitude' => 43.80, 'longitude' => 18.40],
                ['latitude' => 43.81, 'longitude' => 18.41],
                ['latitude' => 43.79, 'longitude' => 18.42],
            ],
            'holes' => [],
        ]);

        Topic::query()->create([
            'mahala_id' => $firstMahala->id,
            'name' => 'Glavna',
            'slug' => 'glavna',
            'description' => 'Glavni lokalni tok za sve oko tebe',
            'is_system' => true,
        ]);
        Topic::query()->create([
            'mahala_id' => $secondMahala->id,
            'name' => 'Eventi',
            'slug' => 'eventi',
            'description' => 'Dešavanja, okupljanja, svirke i lokalni događaji',
        ]);
        Topic::query()->create([
            'mahala_id' => $outsideMahala->id,
            'name' => 'Outside',
            'slug' => 'outside',
            'description' => 'Outside topic',
        ]);

        $this->getJson("/api/topics/current-mahalas?mahala_ids={$firstMahala->id},{$secondMahala->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.mahala_id', $firstMahala->id)
            ->assertJsonPath('data.1.mahala_id', $secondMahala->id);
    }

    public function test_it_creates_topic_for_external_polygon_scope(): void
    {
        $response = $this->postJson('/api/topics', [
            'mahala_id' => '10871',
            'name' => 'Parking patrola',
            'description' => 'Slobodna mjesta, blokirani prolazi i brze dojave oko parkinga.',
            'status' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.mahala_id', '10871')
            ->assertJsonPath('data.slug', 'parking-patrola');

        $this->assertDatabaseHas('topics', [
            'mahala_id' => '10871',
            'slug' => 'parking-patrola',
        ]);
    }

    public function test_user_topic_slug_does_not_collide_with_general_topic_slug(): void
    {
        $response = $this->postJson('/api/topics', [
            'mahala_id' => '10871',
            'name' => 'posao',
            'description' => 'Lokalni razgovori oko poslova.',
            'status' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.mahala_id', '10871')
            ->assertJsonPath('data.slug', 'posao-2');

        $this->assertDatabaseHas('topics', [
            'mahala_id' => '10871',
            'slug' => 'posao-2',
        ]);
    }

    public function test_it_includes_sarajevo_scope_topics_for_sarajevo_polygons(): void
    {
        Topic::query()->create([
            'mahala_id' => '10871',
            'name' => 'Parking patrola',
            'slug' => 'novi-grad-parking-patrola',
            'description' => 'Slobodna mjesta, blokirani prolazi i brze dojave oko parkinga.',
        ]);
        Topic::query()->create([
            'mahala_id' => 'sarajevo-71000',
            'name' => 'Sarajevo servis',
            'slug' => 'sarajevo-servis',
            'description' => 'Gradske dojave za saobraćaj, kvarove, radove i korisne info kroz Sarajevo.',
        ]);
        Topic::query()->create([
            'mahala_id' => 'user-outside-mahala',
            'name' => 'Outside',
            'slug' => 'outside-sarajevo-scope',
            'description' => 'Outside topic',
        ]);

        $this->getJson('/api/topics/current-mahalas?mahala_ids=10871')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.mahala_id', '10871')
            ->assertJsonPath('data.1.mahala_id', 'sarajevo-71000');
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
