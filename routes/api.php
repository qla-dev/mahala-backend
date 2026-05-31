<?php

use App\Http\Controllers\Api\MahalaController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\TopicController;
use Illuminate\Support\Facades\Route;

Route::post('mahalas/bulk-save', [MahalaController::class, 'bulkSave']);
Route::apiResource('mahalas', MahalaController::class);
Route::apiResource('topics', TopicController::class);
Route::apiResource('posts', PostController::class);
