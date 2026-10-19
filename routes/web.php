<?php

use Illuminate\Support\Facades\Route;

// Single-page app shell. React Router handles every non-API path.
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api/|sanctum/|storage/|up$).*$')
    ->name('spa');
