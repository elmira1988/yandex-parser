<template>
    <div>
        <Head title="Главная" />
        <!-- Шапка личного кабинета (Аналог оригинального Хедера) -->
        <header class="bg-white border-bottom py-3 shadow-sm">
            <div class="container d-flex justify-content-between align-items-center">
                <h2 class="h5 fw-bold text-dark mb-0">Dashboard</h2>

                <!-- Кнопка безопасного выхода из системы -->
                <button @click="logout" class="btn btn-outline-secondary btn-sm fw-medium">
                    Выйти
                </button>
            </div>
        </header>

        <!-- Главный контент (Точная копия стиля Breeze на Bootstrap) -->
        <main class="py-5 bg-light" style="min-height: calc(100vh - 62px);">
            <div class="container" style="max-width: 1140px;">
                <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
                    <div class="card-body p-4 text-dark">
                        You're logged in!
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import { Head, router } from '@inertiajs/vue3';

// Метод выхода из системы через роуты Breeze
const logout = () => {
    router.post(route('logout'));
};
</script>
