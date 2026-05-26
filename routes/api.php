<?php

use App\Http\Controllers\Api\MahalaController;
use Illuminate\Support\Facades\Route;

Route::post('mahalas/bulk-save', [MahalaController::class, 'bulkSave']);
Route::apiResource('mahalas', MahalaController::class);
