<?php

use App\Http\Controllers\IndexPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [IndexPageController::class, 'index'])->name('home');
Route::get('/affiliate', [IndexPageController::class, 'getAffiliates'])->name('affiliate');
