<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
Route::any('{any}', function (Request $request) {
    \Log::info('FRONTEND REQUEST: ' . $request->method() . ' ' . $request->fullUrl() . ' User: ' . ($request->user() ? $request->user()->id : 'Guest'));
})->where('any', '.*');
