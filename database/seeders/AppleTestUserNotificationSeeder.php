<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AppleTestUserNotificationSeeder extends Seeder
{
    private const APPLE_USERNAME = 'apple-test-user';

    private const CURRENT_SCOPE_IDS = [
        'sarajevo-71000',
        '10871',
        'user-dobrinja',
        'user-dobrinja-1',
        'user-dobrinja-2',
        'user-dobrinja-3',
        'user-c5',
    ];

    private const REAL_USERNAMES = [
        'amina',
        'lejla',
        'selma',
        'emir',
        'tarik',
    ];

    private const COMMENT_CONTENTS = [
        'Ako još vrijedi, mogu javiti komšijama iz ulaza.',
        'Ovo je dobra ideja, mogu donijeti još par stvari ako se skupimo.',
        'Prošao sam maloprije tuda, informacija je i dalje tačna.',
        'Može, samo javite tačan termin da ne promašim.',
        'Za ovu kafu se prijavljujem odmah, pogotovo ako je poziv ovako fino upakovan.',
        'Javio sam rođaku za ovaj posao, baš traži nešto u drugoj smjeni.',
        'Ovo je korisna informacija, provjerim još sa komšijama iz ulaza.',
        'Može, samo napišite tačno gdje da se preuzme.',
        'Može, javim ovdje ako se nešto promijeni.',
        'Pratim, javim ovdje ako se šta promijeni.',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $appleUser = $this->seedAppleUser();
            $users = $this->existingRealUsers();
            $now = Carbon::now();
            $this->removeBadOldScenario();
            $this->cleanupPreviousRun($appleUser, $users);
            $posts = $this->appleCurrentPosts($appleUser);
            $postByTopic = $this->postsByTopic($posts);

            $commentForVote = $this->appleCommentForVote($appleUser, $posts[4], $now->copy()->subMinutes(20));

            $this->createPostCommentNotification(
                appleUser: $appleUser,
                post: $postByTopic['dating'] ?? $posts[0],
                fromUser: $users['lejla'],
                isAnonymous: false,
                content: 'Za ovu kafu se prijavljujem odmah, pogotovo ako je poziv ovako fino upakovan.',
                createdAt: $now->copy()->subMinutes(7),
            );
            $this->createPostVoteNotification($appleUser, $posts[1], $users['selma'], 1, $now->copy()->subMinutes(13));
            $this->createPostCommentNotification(
                appleUser: $appleUser,
                post: $postByTopic['posao'] ?? $posts[2],
                fromUser: $users['emir'],
                isAnonymous: false,
                content: 'Javio sam rođaku za ovaj posao, baš traži nešto u drugoj smjeni.',
                createdAt: $now->copy()->subMinutes(19),
            );
            $this->createPostVoteNotification($appleUser, $posts[3], $users['tarik'], -1, $now->copy()->subMinutes(25));
            $this->createPostCommentNotification(
                appleUser: $appleUser,
                post: $posts[5],
                fromUser: $users['amina'],
                isAnonymous: false,
                content: 'Ovo je korisna informacija, provjerim još sa komšijama iz ulaza.',
                createdAt: $now->copy()->subMinutes(31),
            );
            $this->createCommentVoteNotification($appleUser, $posts[4], $commentForVote, $users['amina'], $now->copy()->subMinutes(37));
            $this->createPostCommentNotification(
                appleUser: $appleUser,
                post: $posts[6],
                fromUser: null,
                isAnonymous: true,
                content: 'Može, samo napišite tačno gdje da se preuzme.',
                createdAt: $now->copy()->subMinutes(43),
            );
        });
    }

    private function seedAppleUser(): User
    {
        $user = User::query()->updateOrCreate(
            ['username' => self::APPLE_USERNAME],
            [
                'name' => 'Apple Test User',
                'email' => 'apple-test-user@seed.mahala.test',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ],
        );

        $this->ensureSettings($user);

        return $user;
    }

    private function existingRealUsers()
    {
        return collect(self::REAL_USERNAMES)
            ->mapWithKeys(function (string $username) {
                $user = User::query()
                    ->where('username', $username)
                    ->firstOrFail();

                $this->ensureSettings($user);

                return [$username => $user];
            });
    }

    private function ensureSettings(User $user): void
    {
        UserSetting::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'notifications_comments' => true,
                'notifications_votes' => true,
                'notifications_location' => true,
                'notifications_startup_mahalas' => true,
                'locale' => 'bs',
                'pro_status' => UserSetting::PRO_INACTIVE,
            ],
        );
    }

    private function appleCurrentPosts(User $appleUser)
    {
        $posts = Post::query()
            ->where('author_user_id', $appleUser->id)
            ->where('status', 1)
            ->where(function ($query) {
                $query->whereNull('hidden')->orWhere('hidden', false);
            })
            ->whereIn('mahala_id', self::CURRENT_SCOPE_IDS)
            ->where('content', '!=', '[apple-test-user-notifications] Objava za testiranje tacno sedam notifikacija.')
            ->latest()
            ->limit(50)
            ->get()
            ->values();

        if ($posts->count() < 7) {
            throw new \RuntimeException('Apple notification seed needs at least 7 existing current posts by apple-test-user. Run CurrentLocationActivitySeeder first.');
        }

        return $posts;
    }

    private function removeBadOldScenario(): void
    {
        Post::query()
            ->where('content', '[apple-test-user-notifications] Objava za testiranje tacno sedam notifikacija.')
            ->get()
            ->each(fn (Post $post) => $post->delete());
    }

    private function cleanupPreviousRun(User $appleUser, $users): void
    {
        $notificationCommentIds = Notification::query()
            ->where('user_id', $appleUser->id)
            ->whereNotNull('related_comment_id')
            ->pluck('related_comment_id');

        Notification::query()
            ->where('user_id', $appleUser->id)
            ->delete();

        $commentIds = Comment::query()
            ->where(function ($query) use ($notificationCommentIds) {
                $query->whereIn('content', self::COMMENT_CONTENTS);

                if ($notificationCommentIds->isNotEmpty()) {
                    $query->orWhereIn('id', $notificationCommentIds);
                }
            })
            ->pluck('id');

        if ($commentIds->isNotEmpty()) {
            CommentVote::query()
                ->whereIn('reply_id', $commentIds)
                ->delete();

            Comment::query()
                ->whereIn('id', $commentIds)
                ->delete();
        }

        PostVote::query()
            ->whereIn('user_id', [$users['selma']->id, $users['tarik']->id])
            ->whereHas('post', fn ($query) => $query->where('author_user_id', $appleUser->id))
            ->delete();
    }

    private function postsByTopic($posts): array
    {
        return $posts
            ->keyBy('topic_id')
            ->all();
    }

    private function createPostCommentNotification(User $appleUser, Post $post, ?User $fromUser, bool $isAnonymous, string $content, Carbon $createdAt): void
    {
        $comment = Comment::query()->create([
            'post_id' => $post->id,
            'parent_id' => null,
            'author' => $fromUser?->id,
            'content' => $content,
            'is_anonymous' => $isAnonymous,
            'status' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        Notification::query()->create([
            'user_id' => $appleUser->id,
            'from_user_id' => $isAnonymous ? null : $fromUser?->id,
            'type' => Notification::TYPE_COMMENT,
            'vote_value' => null,
            'title' => 'comment',
            'body' => 'post_comment',
            'related_post_id' => $post->id,
            'related_comment_id' => $comment->id,
            'read_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function appleCommentForVote(User $appleUser, Post $post, Carbon $createdAt): Comment
    {
        return Comment::query()->create([
            'post_id' => $post->id,
            'parent_id' => null,
            'author' => $appleUser->id,
            'content' => 'Pratim, javim ovdje ako se šta promijeni.',
            'is_anonymous' => false,
            'status' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function createPostVoteNotification(User $appleUser, Post $post, User $fromUser, int $value, Carbon $createdAt): void
    {
        PostVote::query()->updateOrCreate(
            [
                'post_id' => $post->id,
                'user_id' => $fromUser->id,
            ],
            [
                'value' => $value,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ],
        );

        Notification::query()->create([
            'user_id' => $appleUser->id,
            'from_user_id' => $fromUser->id,
            'type' => Notification::TYPE_VOTE,
            'vote_value' => $value,
            'title' => 'vote',
            'body' => 'post_vote',
            'related_post_id' => $post->id,
            'related_comment_id' => null,
            'read_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function createCommentVoteNotification(User $appleUser, Post $post, Comment $comment, User $voter, Carbon $createdAt): void
    {
        CommentVote::query()->updateOrCreate(
            [
                'reply_id' => $comment->id,
                'user_id' => $voter->id,
            ],
            [
                'value' => 1,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ],
        );

        Notification::query()->create([
            'user_id' => $appleUser->id,
            'from_user_id' => null,
            'type' => Notification::TYPE_VOTE,
            'vote_value' => 1,
            'title' => 'vote',
            'body' => 'comment_vote',
            'related_post_id' => $post->id,
            'related_comment_id' => $comment->id,
            'read_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
