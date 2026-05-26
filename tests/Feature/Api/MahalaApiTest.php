<?php

namespace Tests\Feature\Api;

use App\Models\Mahala;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MahalaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_mahalas(): void
    {
        Mahala::query()->create([
            'id' => 'user-test-mahala',
            'name' => 'Test Mahala',
            'slug' => 'test-mahala',
            'status' => 'published',
            'privacy' => 0,
            'level' => 2,
            'latitude' => 43.8500000,
            'longitude' => 18.4200000,
            'coordinates' => [
                ['latitude' => 43.85, 'longitude' => 18.42],
                ['latitude' => 43.86, 'longitude' => 18.43],
                ['latitude' => 43.84, 'longitude' => 18.44],
            ],
            'holes' => [],
        ]);

        $response = $this->getJson('/api/mahalas');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'user-test-mahala')
            ->assertJsonPath('data.0.name', 'Test Mahala')
            ->assertJsonPath('data.0.slug', 'test-mahala')
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.privacy', 0)
            ->assertJsonPath('data.0.level', 2);
    }

    public function test_it_creates_a_mahala_without_id_or_center(): void
    {
        $owner = User::factory()->create();

        $response = $this->postJson('/api/mahalas', [
            'name' => 'New Test Mahala',
            'status' => 'draft',
            'privacy' => 1,
            'owner_id' => $owner->id,
            'coordinates' => [
                ['latitude' => 43.85, 'longitude' => 18.42],
                ['latitude' => 43.86, 'longitude' => 18.43],
                ['latitude' => 43.84, 'longitude' => 18.44],
            ],
            'holes' => [],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', 'user-new-test-mahala')
            ->assertJsonPath('data.name', 'New Test Mahala')
            ->assertJsonPath('data.slug', 'new-test-mahala')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.privacy', 1)
            ->assertJsonPath('data.owner_id', $owner->id)
            ->assertJsonPath('data.level', 2);

        $this->assertEqualsWithDelta(43.85, $response->json('data.center.latitude'), 0.000001);
        $this->assertEqualsWithDelta(18.43, $response->json('data.center.longitude'), 0.000001);
        $this->assertDatabaseHas('mahalas', [
            'id' => 'user-new-test-mahala',
            'name' => 'New Test Mahala',
            'slug' => 'new-test-mahala',
            'status' => 'draft',
            'privacy' => 1,
            'owner_id' => $owner->id,
            'level' => 2,
        ]);
    }

    public function test_it_bulk_saves_multiple_mahalas(): void
    {
        Mahala::query()->create([
            'id' => 'user-first-mahala',
            'name' => 'First Mahala',
            'slug' => 'first-mahala',
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

        Mahala::query()->create([
            'id' => 'user-second-mahala',
            'name' => 'Second Mahala',
            'slug' => 'second-mahala',
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

        $response = $this->postJson('/api/mahalas/bulk-save', [
            'mahalas' => [
                [
                    'id' => 'user-first-mahala',
                    'coordinates' => [
                        ['latitude' => 43.851, 'longitude' => 18.421],
                        ['latitude' => 43.861, 'longitude' => 18.431],
                        ['latitude' => 43.841, 'longitude' => 18.441],
                    ],
                    'holes' => [],
                ],
                [
                    'id' => 'user-second-mahala',
                    'coordinates' => [
                        ['latitude' => 43.802, 'longitude' => 18.402],
                        ['latitude' => 43.812, 'longitude' => 18.412],
                        ['latitude' => 43.792, 'longitude' => 18.422],
                    ],
                    'holes' => [],
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'user-first-mahala')
            ->assertJsonPath('data.1.id', 'user-second-mahala');

        $this->assertDatabaseHas('mahalas', [
            'id' => 'user-first-mahala',
            'latitude' => 43.851,
            'longitude' => 18.431,
        ]);

        $this->assertDatabaseHas('mahalas', [
            'id' => 'user-second-mahala',
            'latitude' => 43.802,
            'longitude' => 18.412,
        ]);
    }
}
