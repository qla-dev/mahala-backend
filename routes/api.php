<?php

use App\Http\Controllers\Api\MahalaController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\TopicController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/register-user', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->get('auth/me', [AuthController::class, 'me']);
Route::middleware('auth:sanctum')->post('auth/logout', [AuthController::class, 'logout']);

Route::post('mahalas/bulk-save', [MahalaController::class, 'bulkSave']);
Route::apiResource('mahalas', MahalaController::class);

Route::get('topics/current-mahalas', [TopicController::class, 'currentMahalas']);
Route::apiResource('topics', TopicController::class);

Route::get('feed', [PostController::class, 'feed']);
Route::apiResource('posts', PostController::class);
