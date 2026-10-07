<?php

use Illuminate\Support\Facades\Route;

// This app is an API. The website and the dashboard are the React app in
// ../tradewithcuso; this just confirms the API is up for anyone who opens it.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
]));
