<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {
    }

    public function register(RegisterRequest $request)
    {
        return ApiResponse::success($this->authService->register($request->toDto()), 201);
    }

    public function login(LoginRequest $request)
    {
        return ApiResponse::success($this->authService->login($request->toDto()));
    }

    public function me(Request $request)
    {
        return ApiResponse::success($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success([
            'message' => 'Logged out successfully.',
        ]);
    }
}
