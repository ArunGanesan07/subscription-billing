<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// A static page; all data comes from the authenticated JSON API in the browser.
Route::view('/dashboard', 'dashboard')->name('dashboard');
