<?php

namespace Tests\Feature\Api;

use App\Models\Comment;
use App\Models\ContentReport;
use App\Models\Mahala;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Services\ContentModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentModerationApiTest extends TestCase
{
    use RefreshDatabase;

    private function postFor(User $author): Post
    {
        Mahala::query()->firstOrCreate(['id' => 'moderation-test'], [
            'name' => 'Test', 'slug' => 'moderation-test', 'status' => 'published',
            'privacy' => 0, 'level' => 2, 'latitude' => 35, 'longitude' => 139,
            'coordinates' => [], 'holes' => [],
        ]);

        return Post::query()->create([
            'topic_id' => 'glavna', 'author_user_id' => $author->id,
            'mahala_id' => 'moderation-test', 'content' => 'Reported content',
            'is_anonymous' => true, 'status' => 1, 'hidden' => false,
        ]);
    }

    public function test_report_saves_evidence_notifies_backend_and_blocks_author_without_waiting_for_review(): void
    {
        $author = User::factory()->create();
        $reporter = User::factory()->create();
        $post = $this->postFor($author);
        Sanctum::actingAs($reporter);

        $this->postJson('/api/content-reports', [
            'content_type' => 'post', 'content_id' => $post->id, 'reason' => 'Harassment',
        ])->assertCreated()->assertJsonPath('blocked_ids.0', $author->id);

        $report = ContentReport::query()->sole();
        $this->assertSame('Reported content', $report->content_snapshot);
        $this->assertSame($author->id, $report->reported_user_id);
        $this->assertNull($report->seen_at);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $report->due_at->timestamp, 2);
        $this->assertDatabaseHas('blocked', ['user_id' => $reporter->id, 'blocked_id' => $author->id]);
        $this->getJson('/api/posts/'.$post->id)->assertNotFound();
        $this->getJson('/api/feed?mahala_ids=moderation-test')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_duplicate_reports_keep_the_original_deadline_and_evidence(): void
    {
        $reporter = User::factory()->create();
        $post = $this->postFor(User::factory()->create());
        $service = app(ContentModerationService::class);
        $first = $service->report($reporter, 'post', $post->id, 'Harassment');
        $this->travel(3)->hours();
        $post->update(['content' => 'Changed later']);
        $again = $service->report($reporter, 'post', $post->id, 'Another reason');
        $this->assertSame($first->id, $again->id);
        $this->assertTrue($first->due_at->equalTo($again->due_at));
        $this->assertSame('Reported content', $again->content_snapshot);
        $this->assertDatabaseCount('content_reports', 1);
    }

    public function test_comment_reports_resolve_the_author_from_the_database(): void
    {
        $reporter = User::factory()->create();
        $author = User::factory()->create();
        $post = $this->postFor(User::factory()->create());
        $comment = Comment::query()->create(['post_id' => $post->id, 'author' => $author->id, 'content' => 'Abusive reply', 'is_anonymous' => true, 'status' => 1]);
        Sanctum::actingAs($reporter);
        $this->postJson('/api/content-reports', [
            'content_type' => 'comment', 'content_id' => $comment->id, 'reason' => 'Abusive comment',
            'reported_user_id' => $post->author_user_id,
        ])->assertCreated()->assertJsonPath('blocked_ids.0', $author->id);
        $this->assertDatabaseHas('content_reports', ['content_type' => 'comment', 'reported_user_id' => $author->id, 'content_snapshot' => 'Abusive reply']);
        $this->getJson('/api/posts/'.$post->id.'/comments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_legacy_block_endpoint_also_creates_a_backend_alert(): void
    {
        $author = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/blocked', ['blocked_id' => $author->id])->assertOk();
        $this->assertDatabaseHas('content_reports', ['content_type' => 'user', 'reported_user_id' => $author->id, 'status' => 'pending', 'seen_at' => null]);
    }

    public function test_report_queue_and_reviews_are_restricted_to_configured_moderators(): void
    {
        $author = User::factory()->create();
        $post = $this->postFor($author);
        $reporter = User::factory()->create();
        $report = app(ContentModerationService::class)->report($reporter, 'post', $post->id, 'Harassment');
        Sanctum::actingAs($reporter);
        $this->getJson('/api/moderation/reports')->assertForbidden();
        $this->patchJson('/api/moderation/reports/'.$report->id, ['action' => 'remove', 'note' => 'Confirmed'])->assertForbidden();
        $this->assertNull($author->fresh()->suspended_at);

        $moderator = User::factory()->create();
        config(['moderation.moderator_ids' => [$moderator->id]]);
        Sanctum::actingAs($moderator);
        $this->getJson('/api/moderation/reports')->assertOk()->assertJsonPath('alerts.unread', 1)->assertJsonPath('reports.data.0.id', $report->id);
        $this->patchJson('/api/moderation/reports/'.$report->id, ['action' => 'seen'])->assertOk();
        $this->getJson('/api/moderation/reports')->assertOk()->assertJsonPath('alerts.unread', 0);
        $author->createToken('existing-session');
        $this->patchJson('/api/moderation/reports/'.$report->id, ['action' => 'remove', 'note' => 'Confirmed harassment'])
            ->assertOk()->assertJsonPath('data.status', 'removed');
        $this->assertNotNull($author->fresh()->suspended_at);
        $this->assertSame(0, $author->tokens()->count());
        $this->assertTrue($post->fresh()->hidden);
        $this->getJson('/api/posts/'.$post->id)->assertNotFound();
    }

    public function test_dismissal_does_not_suspend_the_author_or_remove_content(): void
    {
        $author = User::factory()->create();
        $post = $this->postFor($author);
        $report = app(ContentModerationService::class)->report(User::factory()->create(), 'post', $post->id, 'Mistaken report', false);
        app(ContentModerationService::class)->review($report, 'dismiss', 'test-moderator', 'No violation');
        $this->assertSame('dismissed', $report->fresh()->status);
        $this->assertNull($author->fresh()->suspended_at);
        $this->assertSame(1, $post->fresh()->status);
    }

    public function test_suspended_accounts_cannot_log_in_or_use_existing_authenticated_sessions(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $user->forceFill(['suspended_at' => now()])->save();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123', 'terms_accepted' => true])->assertForbidden();
        Sanctum::actingAs($user);
        $this->postJson('/api/posts', [])->assertForbidden();
    }

    public function test_all_login_paths_require_explicit_terms_acceptance(): void
    {
        Http::fake();
        foreach (['login' => ['email' => 'test@example.com', 'password' => 'password123'], 'google' => ['id_token' => 'token'], 'apple' => ['identity_token' => 'token']] as $path => $payload) {
            $this->postJson('/api/auth/'.$path, $payload)->assertUnprocessable()->assertJsonValidationErrors('terms_accepted');
        }
        Http::assertNothingSent();
    }

    public function test_login_records_the_accepted_terms_version(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123', 'terms_accepted' => true])->assertOk();
        $this->assertSame(config('moderation.terms_version'), $user->fresh()->terms_version);
        $this->assertNotNull($user->fresh()->terms_accepted_at);
    }

    public function test_disabled_moderation_keeps_posts_and_comments_unpublished_and_prevents_author_spoofing(): void
    {
        config(['services.post_ai_moderation.enabled' => false]);
        $author = User::factory()->create();
        $other = User::factory()->create();
        $post = $this->postFor($other);
        Sanctum::actingAs($author);
        $this->postJson('/api/posts', ['topic_id' => 'glavna', 'mahala_id' => $post->mahala_id, 'content' => 'Unchecked post', 'status' => 1])
            ->assertCreated()->assertJsonPath('data.status', 0);
        $this->postJson('/api/posts/'.$post->id.'/comments', ['content' => 'Unchecked reply', 'author' => $other->id, 'status' => 1])
            ->assertCreated()->assertJsonPath('data.status', 0);
        $this->assertDatabaseHas('comments', ['content' => 'Unchecked reply', 'author' => $author->id, 'status' => 0]);
    }

    public function test_guests_and_self_reports_are_rejected(): void
    {
        $author = User::factory()->create();
        $post = $this->postFor($author);
        $payload = ['content_type' => 'post', 'content_id' => $post->id, 'reason' => 'Test report'];
        $this->postJson('/api/content-reports', $payload)->assertUnauthorized();
        Sanctum::actingAs($author);
        $this->postJson('/api/content-reports', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('content_reports', 0);
    }

    public function test_support_messages_are_saved_in_the_backend_and_not_exposed_to_other_users(): void
    {
        $this->postJson('/api/support-requests', ['name' => 'Test User', 'email' => 'support-test@example.com', 'message' => 'Please investigate this safety issue.'])->assertCreated();
        $this->assertDatabaseHas('support_requests', ['email' => 'support-test@example.com']);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/moderation/support')->assertForbidden();
    }

    public function test_topic_edits_cannot_bypass_moderation_or_change_another_authors_content(): void
    {
        $author = User::factory()->create();
        $post = $this->postFor($author);
        $topic = Topic::query()->create([
            'mahala_id' => $post->mahala_id, 'created_by_user_id' => $author->id,
            'name' => 'Original topic', 'slug' => 'original-topic', 'description' => 'Original description', 'status' => 1,
        ]);
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson('/api/topics/'.$topic->id, ['name' => 'Unauthorized change'])->assertForbidden();
        Sanctum::actingAs($author);
        config(['services.post_ai_moderation.enabled' => false]);
        $this->patchJson('/api/topics/'.$topic->id, ['name' => 'Unchecked edit'])->assertUnprocessable();
        $this->assertSame('Original topic', $topic->fresh()->name);
        config(['services.post_ai_moderation.enabled' => true, 'services.openrouter.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode(['allowed' => false, 'reason' => 'Harassment'])]]]], 200)]);
        $this->patchJson('/api/topics/'.$topic->id, ['name' => 'Rejected edit'])->assertUnprocessable();
        $this->assertSame('Original topic', $topic->fresh()->name);
    }
}
