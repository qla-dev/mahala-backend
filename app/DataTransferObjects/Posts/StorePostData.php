<?php

namespace App\DataTransferObjects\Posts;

readonly class StorePostData
{
    public function __construct(
        public int $userId,
        public string $authorUsername,
        public string $content,
        public ?int $mahalaId,
        public ?int $channelId,
        public string $colorClass,
        public bool $isAnonymous,
        public bool $isImage,
        public ?string $imageUrl,
        public ?float $latitude,
        public ?float $longitude,
    ) {
    }
}
