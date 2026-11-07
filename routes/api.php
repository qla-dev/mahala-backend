<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\MahalaController;
use App\Http\Controllers\Api\BlockedController;
use App\Http\Controllers\Api\ContentReportController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\SupportRequestController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ClientLogController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\LocationDebugReportController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProfileDataController;
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
    Route::post('forgot-password/code', [AuthController::class, 'sendPasswordResetCode']);
    Route::post('forgot-password/verify', [AuthController::class, 'verifyPasswordResetCode']);
    Route::post('forgot-password/reset', [AuthController::class, 'resetForgottenPassword']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('google', [AuthController::class, 'google']);
    Route::post('apple', [AuthController::class, 'apple']);
});

Route::get('startup', StartupController::class);
Route::post('support-requests', [SupportRequestController::class, 'store'])->middleware('throttle:5,10');
Route::get('feed', [PostController::class, 'feed']);
Route::get('profile-data/{type}/{user}', ProfileDataController::class);
Route::get('topics/current-mahalas', [TopicController::class, 'currentMahalas']);
Route::apiResource('mahalas', MahalaController::class)->only(['index', 'show']);
Route::apiResource('topics', TopicController::class)->only(['index', 'show']);
Route::apiResource('posts', PostController::class)->only(['index', 'show']);
Route::get('posts/{post}/comments', [CommentController::class, 'index']);
Route::prefix('geoeditor/mahalas')->group(function () {
    Route::get('/', [MahalaController::class, 'geoeditorIndex']);
    Route::post('bulk-save', [MahalaController::class, 'bulkSave']);
    Route::delete('bulk-delete/{id}', [MahalaController::class, 'destroyFromGeoeditor']);
});
Route::post('logs', [ClientLogController::class, 'store'])->middleware('throttle:60,1');

// Agent API (docs/agent-api.md): guarded by the agent secret, acts as an allow-listed user.
Route::prefix('agent')->middleware('throttle:20,1')->group(function () {
    Route::get('context', [AgentController::class, 'context'])->name('agent.context');
    Route::post('posts', [AgentController::class, 'storePost'])->name('agent.posts.store');
    Route::post('posts/{post}/comments', [AgentController::class, 'storeComment'])->name('agent.comments.store');
});

Route::middleware(['auth:sanctum', EnsureAccountIsActive::class])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('profile', [AuthController::class, 'updateProfile']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::delete('account', [AuthController::class, 'destroyAccount']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    Route::get('user-settings', [UserSettingController::class, 'show']);
    Route::patch('user-settings', [UserSettingController::class, 'update']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/bulk-see', [NotificationController::class, 'bulkSee']);
    Route::get('blocked', [BlockedController::class, 'index']);
    Route::post('blocked', [BlockedController::class, 'store'])->middleware('throttle:20,1');
    Route::post('content-reports', [ContentReportController::class, 'store'])->middleware('throttle:20,1');
    Route::get('moderation/reports', [ModerationController::class, 'index']);
    Route::patch('moderation/reports/{report}', [ModerationController::class, 'update']);
    Route::get('moderation/support', [ModerationController::class, 'support']);
    Route::patch('moderation/support/{ticket}', [ModerationController::class, 'resolveSupport']);
    Route::post('push-tokens', [PushTokenController::class, 'store']);
    Route::post('revenuecat/sync-pro', [RevenueCatController::class, 'syncPro']);
    Route::post('location-debug-reports', [LocationDebugReportController::class, 'store']);

    
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
    Route::delete('comments/{comment}', [CommentController::class, 'destroy']);
    Route::post('posts/{post}/view', [PostController::class, 'view']);
    Route::post('posts/{post}/vote', [VoteController::class, 'votePost']);
    Route::delete('posts/{post}', [PostController::class, 'destroy']);
    Route::post('comments/{comment}/vote', [VoteController::class, 'voteComment']);
});
