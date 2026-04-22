<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Disable public registration — admin creates students manually
Auth::routes(['register' => false]);
