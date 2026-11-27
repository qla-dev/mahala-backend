<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgentBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_retry_filter_and_edit(): void
    {
        config(['services.agent.secret_hash' => Hash::make('test-key')]);
        $this->getJson('/api/agent/bots')->assertForbidden();
        $this->withHeader('X-Agent-Secret', 'test-key');
        $batch = ['users' => [['username' => 'bot_ajla', 'name' => 'Ajla', 'region' => 'ba']]];
        $id = $this->postJson('/api/agent/bots', $batch)->assertCreated()
            ->assertJsonPath('data.0.bot', true)->json('data.0.id');
        $this->postJson('/api/agent/bots', $batch)->assertOk()->assertJsonPath('created', 0);
        $this->patchJson("/api/agent/bots/$id", ['region' => 'cro'])->assertOk();
        $this->getJson('/api/agent/bots?region=ba')->assertJsonPath('total', 0);
        $this->getJson('/api/agent/bots?region=cro')->assertJsonPath('data.0.id', $id);
    }

    public function test_protects_existing_people_and_rolls_back_on_conflict(): void
    {
        config(['services.agent.secret_hash' => Hash::make('test-key')]);
        $this->withHeader('X-Agent-Secret', 'test-key');
        $person = User::factory()->create(['username' => 'realuser']);
        $this->patchJson("/api/agent/bots/{$person->id}", ['region' => 'ba'])->assertNotFound();
        $this->postJson('/api/agent/bots', ['users' => [
            ['username' => 'bot_new', 'name' => 'Nova'],
            ['username' => 'realuser', 'name' => 'Drugi'],
        ]])->assertConflict();
        $this->assertDatabaseMissing('users', ['username' => 'bot_new']);
    }
}
