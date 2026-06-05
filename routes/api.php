<?php

use App\Http\Controllers\Api\MahalaController;
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

Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/register-user', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/google', [AuthController::class, 'google']);
Route::post('auth/apple', [AuthController::class, 'apple']);
Route::middleware('auth:sanctum')->get('auth/me', [AuthController::class, 'me']);
Route::middleware('auth:sanctum')->patch('auth/profile', [AuthController::class, 'updateProfile']);
Route::middleware('auth:sanctum')->post('auth/logout', [AuthController::class, 'logout']);
Route::middleware('auth:sanctum')->post('auth/change-password', [AuthController::class, 'changePassword']);
Route::middleware('auth:sanctum')->get('user-settings', [UserSettingController::class, 'show']);
Route::middleware('auth:sanctum')->patch('user-settings', [UserSettingController::class, 'update']);
Route::middleware('auth:sanctum')->get('notifications', [NotificationController::class, 'index']);
Route::middleware('auth:sanctum')->post('notifications/bulk-see', [NotificationController::class, 'bulkSee']);
Route::middleware('auth:sanctum')->post('push-tokens', [PushTokenController::class, 'store']);
Route::middleware('auth:sanctum')->post('revenuecat/sync-pro', [RevenueCatController::class, 'syncPro']);
Route::middleware('auth:sanctum')->post('location-debug-reports', [LocationDebugReportController::class, 'store']);

Route::post('mahalas/bulk-save', [MahalaController::class, 'bulkSave']);
Route::apiResource('mahalas', MahalaController::class);

Route::get('startup', StartupController::class);

Route::get('topics/current-mahalas', [TopicController::class, 'currentMahalas']);
Route::apiResource('topics', TopicController::class);

Route::get('feed', [PostController::class, 'feed']);
Route::get('posts/{post}/comments', [CommentController::class, 'index']);
Route::post('posts/{post}/comments', [CommentController::class, 'store']);
Route::middleware('auth:sanctum')->post('posts/{post}/vote', [VoteController::class, 'votePost']);
Route::middleware('auth:sanctum')->post('comments/{comment}/vote', [VoteController::class, 'voteComment']);
Route::apiResource('posts', PostController::class);
