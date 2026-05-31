<?php

namespace Tests\Feature\Api;

use App\Models\Mahala;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'name' => 'Prodajem i kupujem',
            'description' => 'Kupovina, prodaja, razmjena i lokalne ponude',
            'color_hex' => '#2dd4bf',
            'is_premium' => true,
            'is_system' => false,
            'status' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.mahala_id', $mahala->id)
            ->assertJsonPath('data.created_by_user_id', $user->id)
            ->assertJsonPath('data.name', 'Prodajem i kupujem')
            ->assertJsonPath('data.slug', 'prodajem-i-kupujem')
            ->assertJsonPath('data.color_hex', '#2dd4bf')
            ->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.status', 1);

        $this->assertDatabaseHas('topics', [
            'id' => $response->json('data.id'),
            'mahala_id' => $mahala->id,
            'slug' => 'prodajem-i-kupujem',
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
            'color_hex' => '#7c3aed',
            'is_premium' => false,
            'is_system' => true,
            'status' => 1,
        ]);

        $response = $this->postJson('/api/posts', [
            'channel_id' => 'glavna-' . $mahala->id,
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
            ->assertJsonPath('data.channel_id', 'glavna-' . $mahala->id)
            ->assertJsonPath('data.author_user_id', $user->id)
            ->assertJsonPath('data.mahala_id', $mahala->id)
            ->assertJsonPath('data.content', 'Ima li ko za kafu?')
            ->assertJsonPath('data.color_hex', '#7c3aed')
            ->assertJsonPath('data.is_anonymous', true)
            ->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.hidden', false);

        $this->assertDatabaseHas('posts', [
            'id' => $response->json('data.id'),
            'channel_id' => 'glavna-' . $mahala->id,
            'mahala_id' => $mahala->id,
        ]);

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $response->json('data.id'));
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
            'channel_id' => 'glavna-' . $firstMahala->id,
            'mahala_id' => $firstMahala->id,
            'content' => 'First feed post',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);
        \App\Models\Post::query()->create([
            'channel_id' => 'glavna-' . $secondMahala->id,
            'mahala_id' => $secondMahala->id,
            'content' => 'Second feed post',
            'is_anonymous' => true,
            'status' => 1,
            'hidden' => false,
        ]);
        \App\Models\Post::query()->create([
            'channel_id' => 'glavna-' . $outsideMahala->id,
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

    public function test_general_channel_post_inherits_color_without_topic_record(): void
    {
        $post = \App\Models\Post::query()->create([
            'channel_id' => 'posao-10871',
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
                'channel_id' => 'posao-10871',
                'color_hex' => '#06b6d4',
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
            'color_hex' => '#7c3aed',
            'is_system' => true,
        ]);
        Topic::query()->create([
            'mahala_id' => $secondMahala->id,
            'name' => 'Eventi',
            'slug' => 'eventi',
            'description' => 'Dešavanja, okupljanja, svirke i lokalni događaji',
            'color_hex' => '#ec4899',
        ]);
        Topic::query()->create([
            'mahala_id' => $outsideMahala->id,
            'name' => 'Outside',
            'slug' => 'outside',
            'description' => 'Outside topic',
            'color_hex' => '#06b6d4',
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
            'color_hex' => '#f97316',
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

    public function test_it_includes_sarajevo_scope_topics_for_sarajevo_polygons(): void
    {
        Topic::query()->create([
            'mahala_id' => '10871',
            'name' => 'Parking patrola',
            'slug' => 'novi-grad-parking-patrola',
            'description' => 'Slobodna mjesta, blokirani prolazi i brze dojave oko parkinga.',
            'color_hex' => '#f97316',
        ]);
        Topic::query()->create([
            'mahala_id' => 'sarajevo-71000',
            'name' => 'Sarajevo servis',
            'slug' => 'sarajevo-servis',
            'description' => 'Gradske dojave za saobraćaj, kvarove, radove i korisne info kroz Sarajevo.',
            'color_hex' => '#7c3aed',
        ]);
        Topic::query()->create([
            'mahala_id' => 'user-outside-mahala',
            'name' => 'Outside',
            'slug' => 'outside-sarajevo-scope',
            'description' => 'Outside topic',
            'color_hex' => '#06b6d4',
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
