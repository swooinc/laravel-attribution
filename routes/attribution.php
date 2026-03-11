<?php

use Illuminate\Support\Facades\Route;
use SwooInc\Attribution\Http\Controllers\SaveConvertingTouchController;
use SwooInc\Attribution\Http\Controllers\SaveAttributionController;

$path = config('attribution.route_path', 'touchpoint');

Route::post($path, SaveAttributionController::class)
    ->name('attribution.save');

Route::post($path . '/converting', SaveConvertingTouchController::class)
    ->name('attribution.converting');
