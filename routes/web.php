<?php

use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ImportController::class, 'create'])->name('imports.create');
Route::post('/import', [ImportController::class, 'store'])->name('imports.store');
