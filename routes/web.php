<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Health check endpoint for Cloud Run
Route::get('/up', function () {
    return response()->json(['status' => 'ok'], 200);
});
