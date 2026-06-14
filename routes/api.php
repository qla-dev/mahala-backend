<?php

use App\Http\Controllers\Api\MahalaController;
use App\Http\Controllers\Api\BlockedController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\LocationDebugReportController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\RevenueCatController;
use App\Http\Controllers\Api\StartupController;
use App\Http\Controllers\Api\TopicController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserSettingController;
use App\Http\Controllers\Api\VoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('register-user', [AuthController::class, 'register']);
    Route::post('register/code', [AuthController::class, 'sendRegistrationCode']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('google', [AuthController::class, 'google']);
    Route::post('apple', [AuthController::class, 'apple']);
});

Route::get('startup', StartupController::class);
Route::get('feed', [PostController::class, 'feed']);
Route::get('topics/current-mahalas', [TopicController::class, 'currentMahalas']);
Route::apiResource('mahalas', MahalaController::class)->only(['index', 'show']);
Route::apiResource('topics', TopicController::class)->only(['index', 'show']);
Route::apiResource('posts', PostController::class)->only(['index', 'show']);
Route::get('posts/{post}/comments', [CommentController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('profile', [AuthController::class, 'updateProfile']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    Route::get('user-settings', [UserSettingController::class, 'show']);
    Route::patch('user-settings', [UserSettingController::class, 'update']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/bulk-see', [NotificationController::class, 'bulkSee']);
    Route::get('blocked', [BlockedController::class, 'index']);
    Route::post('blocked', [BlockedController::class, 'store']);
    Route::post('push-tokens', [PushTokenController::class, 'store']);
    Route::post('revenuecat/sync-pro', [RevenueCatController::class, 'syncPro']);
    Route::post('location-debug-reports', [LocationDebugReportController::class, 'store']);

    Route::post('mahalas/bulk-save', [MahalaController::class, 'bulkSave']);
    Route::post('mahalas', [MahalaController::class, 'store']);
    Route::match(['put', 'patch'], 'mahalas/{mahala}', [MahalaController::class, 'update']);
    Route::delete('mahalas/{mahala}', [MahalaController::class, 'destroy']);

    Route::post('topics', [TopicController::class, 'store']);
    Route::match(['put', 'patch'], 'topics/{topic}', [TopicController::class, 'update']);
    Route::delete('topics/{topic}', [TopicController::class, 'destroy']);

    Route::post('posts', [PostController::class, 'store']);
    Route::post('posts/{post}/comments', [CommentController::class, 'store']);
    Route::post('posts/{post}/retry', [PostController::class, 'retry']);
    Route::post('comments/{comment}/retry', [CommentController::class, 'retry']);
    Route::post('posts/{post}/view', [PostController::class, 'view']);
    Route::post('posts/{post}/vote', [VoteController::class, 'votePost']);
    Route::delete('posts/{post}', [PostController::class, 'destroy']);
    Route::post('comments/{comment}/vote', [VoteController::class, 'voteComment']);
});
