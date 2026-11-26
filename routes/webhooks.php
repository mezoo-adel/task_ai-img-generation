<?php

use App\Http\Controllers\PixelForgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/pixelforge', PixelForgeWebhookController::class)->name('pixelforge');
