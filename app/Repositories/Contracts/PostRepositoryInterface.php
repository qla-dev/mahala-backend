<?php

namespace App\Repositories\Contracts;

use App\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PostRepositoryInterface
{
    public function paginateLatest(int $perPage = 20): LengthAwarePaginator;

    public function create(array $attributes): Post;
}
