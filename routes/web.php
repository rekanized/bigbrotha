<?php

use App\Http\Controllers\CameraFleetStreamPreviewController;
use App\Http\Controllers\CameraFleetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Discovery\OnvifSweepController;
use App\Http\Controllers\LiveWallController;
use App\Http\Controllers\LiveWallStreamController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/camera-fleet', CameraFleetController::class)->name('camera-fleet.index');
Route::get('/camera-fleet/{camera}/profiles/{profileIndex}/preview', CameraFleetStreamPreviewController::class)->name('camera-fleet.preview');
Route::get('/live-wall', LiveWallController::class)->name('live-wall.index');
Route::get('/live-wall/{camera}/stream', [LiveWallStreamController::class, 'mjpeg'])->name('live-wall.stream');
Route::get('/live-wall/{camera}/relay', [LiveWallStreamController::class, 'relay'])->name('live-wall.relay');

Route::prefix('discovery')->name('discovery.')->group(function (): void {
    Route::get('/onvif-sweep', OnvifSweepController::class)->name('onvif-sweep');
});
