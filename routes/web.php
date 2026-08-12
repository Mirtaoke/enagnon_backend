<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'MultiShop API',
    'status' => 'ok',
]));
