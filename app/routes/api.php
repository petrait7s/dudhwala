<?php

use App\Http\Controllers\Api\CatalogueController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/catalogue', [CatalogueController::class, 'index']);
Route::get('/config', [CatalogueController::class, 'config']);
Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:orders');
Route::post('/orders/track', [OrderController::class, 'track'])->middleware('throttle:track');
