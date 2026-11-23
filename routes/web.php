<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'SIAMAG Dishub API is running perfectly.',
        'version' => '1.0.0'
    ]);
});