<?php

namespace App\Repositories\Eloquent;

use App\Models\Post;
use App\Repositories\Contracts\PostRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PostRepository implements PostRepositoryInterface
{
    public function paginateLatest(int $perPage = 20): LengthAwarePaginator
    {
        return Post::query()
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $attributes): Post
    {
        return Post::query()->create($attributes);
    }
}
