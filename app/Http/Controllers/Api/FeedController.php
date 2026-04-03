<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Posts\StorePostRequest;
use App\Services\Posts\FeedService;
use App\Support\ApiResponse;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feedService,
    ) {
    }

    public function index()
    {
        return ApiResponse::success($this->feedService->list());
    }

    public function store(StorePostRequest $request)
    {
        return ApiResponse::success($this->feedService->create($request->toDto()), 201);
    }
}
