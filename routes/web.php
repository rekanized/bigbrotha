<?php

use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\AdminAuditLogController;
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\Auth\GoogleTestCallbackController;
use App\Http\Controllers\Auth\GoogleTestRedirectController;
use App\Http\Controllers\CameraFleetStreamPreviewController;
use App\Http\Controllers\CameraFleetMotionEditorSessionController;
use App\Http\Controllers\CameraFleetController;
use App\Http\Controllers\LiveWallController;
use App\Http\Controllers\LiveWallPlayerController;
use App\Http\Controllers\LiveWallSessionController;
use App\Http\Controllers\LiveWallStreamController;
use App\Http\Controllers\RecordingController;
use App\Http\Controllers\Relay\MediaMtxAuthController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\WallTilesController;
use Illuminate\Support\Facades\Route;

Route::get('/setup', SetupController::class)->name('setup.index');
Route::get('/auth/google/test/redirect', GoogleTestRedirectController::class)->name('auth.google.test.redirect');
Route::get('/auth/google/test/callback', GoogleTestCallbackController::class)->name('auth.google.test.callback');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', fn () => view('auth.login'))->name('login');
    Route::get('/auth/google/redirect', GoogleRedirectController::class)->name('auth.google.redirect');
    Route::get('/auth/google/callback', GoogleCallbackController::class)->name('auth.google.callback');
});

Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');
Route::post('/relay/auth/mediamtx', MediaMtxAuthController::class)->name('relay.auth.mediamtx');

Route::middleware('auth')->group(function (): void {
    Route::redirect('/', '/camera-fleet');
    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function (): void {
        Route::get('/users', [AdminUsersController::class, 'index'])->name('users.index');
        Route::post('/users/allowed-emails', [AdminUsersController::class, 'storeAllowedEmail'])->name('users.allowed-emails.store');
        Route::post('/users/local-accounts', [AdminUsersController::class, 'storeLocalUser'])->name('users.local-accounts.store');
        Route::delete('/users/allowed-emails/{allowedLoginEmail}', [AdminUsersController::class, 'destroyAllowedEmail'])->name('users.allowed-emails.destroy');
        Route::put('/users/{user}/local-password', [AdminUsersController::class, 'updateLocalPassword'])->name('users.local-password');
        Route::put('/users/{user}/admin-role', [AdminUsersController::class, 'updateAdminRole'])->name('users.admin-role');
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        Route::get('/audit-log', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');
    });

    Route::get('/camera-fleet', CameraFleetController::class)->name('camera-fleet.index');
    Route::get('/camera-fleet/{camera}/profiles/{profileIndex}/preview', CameraFleetStreamPreviewController::class)->name('camera-fleet.preview');
    Route::get('/camera-fleet/{camera}/motion-editor-session', CameraFleetMotionEditorSessionController::class)
        ->middleware('media-access')
        ->name('camera-fleet.motion-editor-session');
    Route::get('/recordings', [RecordingController::class, 'index'])->name('recordings.index');
    Route::get('/recordings/timeline', [RecordingController::class, 'timeline'])->name('recordings.timeline');
    Route::get('/recordings/timeline/cameras/{camera}/segments', [RecordingController::class, 'timelineRailData'])->name('recordings.timeline.rail-data');
    Route::get('/recordings/timeline/cameras/{camera}/stage', [RecordingController::class, 'timelineStageData'])->name('recordings.timeline.stage-data');
    Route::get('/recordings/{recording}', [RecordingController::class, 'show'])->name('recordings.show');
    Route::get('/recordings/{recording}/preview-thumbnail', [RecordingController::class, 'previewThumbnail'])->name('recordings.preview-thumbnail');
    Route::get('/recordings/{recording}/preview-sprite', [RecordingController::class, 'previewSprite'])->name('recordings.preview-sprite');
    Route::get('/recordings/{recording}/review-stream', [RecordingController::class, 'reviewStream'])->middleware('media-access')->name('recordings.review-stream');
    Route::get('/recordings/{recording}/stream', [RecordingController::class, 'stream'])->middleware('media-access')->name('recordings.stream');
    Route::get('/recordings/{recording}/download', [RecordingController::class, 'download'])->name('recordings.download');
    Route::get('/live-wall', LiveWallController::class)->middleware('media-access')->name('live-wall.index');
    Route::get('/wall-tiles', WallTilesController::class)->name('wall-tiles.index');
    Route::get('/live-wall/{camera}/player', LiveWallPlayerController::class)->middleware('media-access')->name('live-wall.player');
    Route::get('/live-wall/{camera}/session', LiveWallSessionController::class)->middleware('media-access')->name('live-wall.session');
    Route::get('/live-wall/{camera}/stream', [LiveWallStreamController::class, 'mjpeg'])->middleware('media-access')->name('live-wall.stream');
    Route::get('/live-wall/{camera}/relay', [LiveWallStreamController::class, 'relay'])->middleware('media-access')->name('live-wall.relay');
});
