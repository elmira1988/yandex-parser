<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
class YandexController extends Controller
{
    // Временная заглушка: просто показываем страницу личного кабинета
    public function index()
    {
        return Inertia::render('Dashboard');
    }
}
