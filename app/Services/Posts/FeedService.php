<?php

namespace App\Services\Posts;

use App\DataTransferObjects\Posts\StorePostData;
use App\Repositories\Contracts\PostRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FeedService
{
    public function __construct(
        private readonly PostRepositoryInterface $posts,
    ) {
    }

    public function list(): LengthAwarePaginator
    {
        return $this->posts->paginateLatest();
    }

    public function create(StorePostData $data)
    {
        return DB::transaction(fn () => $this->posts->create([
            'author_user_id' => $data->userId,
            'author_username' => $data->authorUsername,
            'mahala_id' => $data->mahalaId,
            'channel_id' => $data->channelId,
            'content' => $data->content,
            'votes_count' => 0,
            'replies_count' => 0,
            'color_class' => $data->colorClass,
            'is_anonymous' => $data->isAnonymous,
            'is_image' => $data->isImage,
            'image_url' => $data->imageUrl,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
        ]));
    }
}
