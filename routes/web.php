<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Include health check route for Railway monitoring
require __DIR__.'/health.php';
