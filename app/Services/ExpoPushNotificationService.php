<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushNotificationService
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    public function sendNotification(Notification $notification): void
    {
        try {
            $notification->loadMissing(['user.settings', 'fromUser', 'relatedPost', 'relatedComment']);

            $category = in_array($notification->type, [Notification::TYPE_COMMENT, Notification::TYPE_COMMENT_REPLY], true)
                ? 'comments'
                : 'votes';

            $settings = $notification->user?->settings;

            if (
                ($category === 'comments' && !$settings?->notifications_comments) ||
                ($category === 'votes' && !$settings?->notifications_votes)
            ) {
                return;
            }

            $tokens = PushToken::query()
                ->where('user_id', $notification->user_id)
                ->where('provider', 'expo')
                ->whereNull('disabled_at')
                ->get()
                ->filter(fn (PushToken $token) => $this->allowsCategory($token, $category));

            if ($tokens->isEmpty()) {
                return;
            }

            $messages = $tokens
                ->map(fn (PushToken $token) => $this->messageFor($token, $notification))
                ->values()
                ->all();

            $response = Http::timeout(5)
                ->acceptJson()
                ->asJson()
                ->post(self::EXPO_PUSH_URL, $messages);

            if (!$response->successful()) {
                $error = $response->body();
                $tokens->each(fn (PushToken $token) => $token->forceFill(['last_error' => $error])->save());
                Log::warning('[MAHALA][push] Expo push request failed', [
                    'notification_id' => $notification->id,
                    'status' => $response->status(),
                    'body' => $error,
                ]);
                return;
            }

            $this->applyExpoResult($tokens->values(), $response->json('data') ?? []);
        } catch (\Throwable $error) {
            Log::warning('[MAHALA][push] Expo push send failed', [
                'notification_id' => $notification->id,
                'message' => $error->getMessage(),
            ]);
        }
    }

    private function allowsCategory(PushToken $token, string $category): bool
    {
        $preferences = $token->preferences ?: [];

        if (($preferences['push'] ?? $preferences['app'] ?? true) === false) {
            return false;
        }

        return ($preferences[$category] ?? true) !== false;
    }

    private function messageFor(PushToken $token, Notification $notification): array
    {
        return array_filter([
            'to' => $token->token,
            'title' => $this->titleFor($notification),
            'body' => $this->bodyFor($notification),
            'sound' => $token->sound ?: 'new-notification.mp3',
            'channelId' => $token->notification_channel_id ?: 'mahala-notifications',
            'priority' => 'default',
            'data' => [
                'type' => 'notification',
                'notificationId' => $notification->id,
                'notificationType' => $notification->type,
                'relatedPostId' => $notification->related_post_id,
                'relatedCommentId' => $notification->related_comment_id,
            ],
        ], fn ($value) => $value !== null);
    }

    private function titleFor(Notification $notification): string
    {
        return $notification->type === Notification::TYPE_COMMENT
            ? 'Novi komentar'
            : ($notification->type === Notification::TYPE_COMMENT_REPLY
                ? 'Novi odgovor'
                : 'Novi glas');
    }

    private function bodyFor(Notification $notification): string
    {
        $actor = $notification->fromUser?->username
            ? '@'.$notification->fromUser->username
            : '@komsija';

        if ($notification->type === Notification::TYPE_COMMENT) {
            return "{$actor} je komentarisao/la tvoju objavu.";
        }

        if ($notification->type === Notification::TYPE_COMMENT_REPLY) {
            return "{$actor} je odgovorio/la na tvoj komentar.";
        }

        $target = $notification->related_comment_id ? 'komentar' : 'objavu';
        $action = ((int) $notification->vote_value) < 0 ? 'downvoteao/la' : 'upvoteao/la';

        return "{$actor} je {$action} tvoj {$target}.";
    }

    private function applyExpoResult($tokens, array $results): void
    {
        foreach ($tokens as $index => $token) {
            $result = $results[$index] ?? null;

            if (($result['status'] ?? null) === 'ok') {
                $token->forceFill([
                    'last_used_at' => now(),
                    'last_error' => null,
                ])->save();
                continue;
            }

            $error = $result['message'] ?? $result['details']['error'] ?? 'Expo push error';
            $updates = ['last_error' => $error];

            if (($result['details']['error'] ?? null) === 'DeviceNotRegistered') {
                $updates['disabled_at'] = now();
            }

            $token->forceFill($updates)->save();
        }
    }
}
