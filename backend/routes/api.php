<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;

Route::prefix('v1')->group(function () {
    // ログインAPIは5回/分のリクエスト制限をかけています
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // ログイン済みでなければ 401。ここから先は認証必須
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
    });
});
