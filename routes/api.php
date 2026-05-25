<?php

use App\Http\Controllers\Api\MahalaController;
use Illuminate\Support\Facades\Route;

Route::apiResource('mahalas', MahalaController::class);
