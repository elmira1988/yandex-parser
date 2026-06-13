<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\YandexController; // Подключаем наш контроллер
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// 1. Корень сайта: строго один роут с проверкой авторизации
Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

// 2. Защищенные роуты личного кабинета (управляются через YandexController)
Route::middleware(['auth', 'verified'])->group(function () {
    // Получение страницы и вывод отзывов по 50 штук по ТЗ
    Route::get('/dashboard', [YandexController::class, 'index'])->name('dashboard');
});

// 3. Системные роуты профиля от Breeze
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// 4. Роуты авторизации (логин, логаут)
require __DIR__.'/auth.php';
