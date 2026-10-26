<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['ok' => true];
});

Route::get('/up', HealthController::class);
