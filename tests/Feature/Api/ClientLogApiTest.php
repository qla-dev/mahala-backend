<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_an_anonymous_client_api_error(): void
    {
        $response = $this->postJson('/api/logs', [
            'source' => 'mobile-api',
            'message' => 'Request failed with status code 500',
            'platform' => 'android',
            'app_version' => '1.2.1',
            'context' => [
                'code' => 'ERR_BAD_RESPONSE',
                'status' => 500,
                'method' => 'GET',
                'url' => 'https://api.mahala.app/public/api/startup',
                'response_data' => ['message' => 'Database unavailable'],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Log je sačuvan.')
            ->assertJsonStructure(['data' => ['id', 'created_at']]);

        $this->assertDatabaseHas('logs', [
            'user_id' => null,
            'level' => 'error',
            'source' => 'mobile-api',
            'message' => 'Request failed with status code 500',
            'platform' => 'android',
            'app_version' => '1.2.1',
        ]);
    }

    public function test_it_rejects_an_invalid_client_log(): void
    {
        $this->postJson('/api/logs', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source', 'message', 'context']);
    }
}
