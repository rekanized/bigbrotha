<?php

use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\CameraFleetStreamPreviewController;
use App\Http\Controllers\CameraFleetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Discovery\OnvifSweepController;
use App\Http\Controllers\LiveWallController;
use App\Http\Controllers\LiveWallPlayerController;
use App\Http\Controllers\LiveWallSessionController;
use App\Http\Controllers\LiveWallStreamController;
use App\Http\Controllers\RecordingController;
use App\Http\Controllers\Relay\MediaMtxAuthController;
use App\Http\Controllers\WallTilesController;
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
    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function (): void {
        Route::get('/users', [AdminUsersController::class, 'index'])->name('users.index');
        Route::put('/users/{user}/admin-role', [AdminUsersController::class, 'updateAdminRole'])->name('users.admin-role');
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
    });

    Route::get('/camera-fleet', CameraFleetController::class)->name('camera-fleet.index');
    Route::get('/camera-fleet/{camera}/profiles/{profileIndex}/preview', CameraFleetStreamPreviewController::class)->name('camera-fleet.preview');
    Route::get('/recordings', [RecordingController::class, 'index'])->name('recordings.index');
    Route::get('/recordings/timeline', [RecordingController::class, 'timeline'])->name('recordings.timeline');
    Route::get('/recordings/{recording}', [RecordingController::class, 'show'])->name('recordings.show');
    Route::get('/recordings/{recording}/preview-stream', [RecordingController::class, 'previewStream'])->name('recordings.preview-stream');
    Route::get('/recordings/{recording}/preview-thumbnail', [RecordingController::class, 'previewThumbnail'])->name('recordings.preview-thumbnail');
    Route::get('/recordings/{recording}/preview-sprite', [RecordingController::class, 'previewSprite'])->name('recordings.preview-sprite');
    Route::get('/recordings/{recording}/review-stream', [RecordingController::class, 'reviewStream'])->name('recordings.review-stream');
    Route::get('/recordings/{recording}/stream', [RecordingController::class, 'stream'])->name('recordings.stream');
    Route::get('/recordings/{recording}/download', [RecordingController::class, 'download'])->name('recordings.download');
    Route::get('/live-wall', LiveWallController::class)->name('live-wall.index');
    Route::get('/wall-tiles', WallTilesController::class)->name('wall-tiles.index');
    Route::get('/live-wall/{camera}/player', LiveWallPlayerController::class)->name('live-wall.player');
    Route::get('/live-wall/{camera}/session', LiveWallSessionController::class)->name('live-wall.session');
    Route::get('/live-wall/{camera}/stream', [LiveWallStreamController::class, 'mjpeg'])->name('live-wall.stream');
    Route::get('/live-wall/{camera}/relay', [LiveWallStreamController::class, 'relay'])->name('live-wall.relay');

    Route::prefix('discovery')->name('discovery.')->group(function (): void {
        Route::get('/onvif-sweep', OnvifSweepController::class)->name('onvif-sweep');
    });
});
