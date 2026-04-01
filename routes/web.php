<?php

use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CameraFleetStreamPreviewController;
use App\Http\Controllers\CameraFleetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Discovery\OnvifSweepController;
use App\Http\Controllers\LiveWallController;
use App\Http\Controllers\LiveWallPlayerController;
use App\Http\Controllers\LiveWallSessionController;
use App\Http\Controllers\LiveWallStreamController;
use App\Http\Controllers\Relay\MediaMtxAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', fn () => view('auth.login'))->name('login');
    Route::get('/auth/google/redirect', GoogleRedirectController::class)->name('auth.google.redirect');
    Route::get('/auth/google/callback', GoogleCallbackController::class)->name('auth.google.callback');
});

Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');
Route::post('/relay/auth/mediamtx', MediaMtxAuthController::class)->name('relay.auth.mediamtx');

Route::middleware('auth')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/camera-fleet', CameraFleetController::class)->name('camera-fleet.index');
    Route::get('/camera-fleet/{camera}/profiles/{profileIndex}/preview', CameraFleetStreamPreviewController::class)->name('camera-fleet.preview');
    Route::get('/live-wall', LiveWallController::class)->name('live-wall.index');
    Route::get('/live-wall/{camera}/player', LiveWallPlayerController::class)->name('live-wall.player');
    Route::get('/live-wall/{camera}/session', LiveWallSessionController::class)->name('live-wall.session');
    Route::get('/live-wall/{camera}/stream', [LiveWallStreamController::class, 'mjpeg'])->name('live-wall.stream');
    Route::get('/live-wall/{camera}/relay', [LiveWallStreamController::class, 'relay'])->name('live-wall.relay');

    Route::prefix('discovery')->name('discovery.')->group(function (): void {
        Route::get('/onvif-sweep', OnvifSweepController::class)->name('onvif-sweep');
    });
});
