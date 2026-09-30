<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (request()->wantsJson()) {
        return response()->json([
            'status' => 'success',
            'service' => 'OpenScore Backend API',
            'version' => '1.0.0',
            'message' => 'This is a backend API of OpenScore',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
    return view('welcome');
});

