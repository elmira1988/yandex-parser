<template>
    <div>
        <Head title="Главная" />

        <!-- Шапка личного кабинета -->
        <header class="bg-white border-bottom py-3 shadow-sm">
            <div class="container d-flex justify-content-between align-items-center">
                <h2 class="h5 fw-bold text-dark mb-0">Панель управления</h2>
                <button @click="logout" class="btn btn-outline-secondary btn-sm fw-medium">Выйти</button>
            </div>
        </header>

        <!-- Главный контент -->
        <main class="py-1 bg-light">
                <!-- 🌟 ОТОБРАЖЕНИЕ КАРТОЧКИ И ОТЗЫВОВ (показывается только если они есть) -->
                <div class="container-fluid py-2" style="max-width: 1400px;">
                    <div class="row g-4">
                        <!-- ================= ЛЕВАЯ КОЛОНКА: ФОРМА + СПИСОК КОМПАНИЙ ================= -->
                        <div class="col-12 col-lg-4">
                            <div class="d-flex flex-column gap-4">

                                <!-- Форма подключения организации  -->
                                <div class="card border-0 shadow-sm rounded-3 p-4 bg-white">
                                    <h3 class="h6 fw-bold text-dark mb-2">Подключение организации</h3>
                                    <p class="text-muted small mb-3">
                                        Нажмите кнопку <strong>«Поделиться»</strong> на карточке организации в Яндекс.Картах и скопируйте короткую ссылку.<br>
                                        <span class="text-secondary d-block mt-1">Пример: <code>https://yandex.ru/maps/-/CPxrrZ55</code></span>
                                    </p>

                                    <form @submit.prevent="submit">
                                        <div class="mb-3">
                                            <label for="yandex_url" class="form-label fw-medium text-secondary small">Ссылка на Яндекс.Карты</label>
                                            <input
                                                id="yandex_url"
                                                type="url"
                                                class="form-control form-control-sm"
                                                :class="{ 'is-invalid': form.errors.yandex_url }"
                                                v-model="form.yandex_url"
                                                placeholder="https://yandex.ru"
                                                required
                                            />
                                            <div v-if="form.errors.yandex_url" class="invalid-feedback small">
                                                {{ form.errors.yandex_url }}
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-dark btn-sm fw-semibold w-100 py-2 rounded-3" :disabled="form.processing">
                                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                                            {{ form.processing ? 'Загрузка...' : 'Подключить и собрать отзывы' }}
                                        </button>
                                    </form>
                                </div>

                                <!-- Список подключенных организаций пользователя -->
                                <div class="d-flex flex-column gap-2">
                                    <h4 class="h6 fw-bold text-secondary text-uppercase text-center tracking-wider mb-1">Мои организации</h4>

                                    <div v-if="!organizations || organizations.length === 0" class="text-muted small p-3 bg-white rounded-3 text-center shadow-sm">
                                        Организации пока не подключены.
                                    </div>

                                    <div
                                        v-else
                                        v-for="org in organizations"
                                        :key="org.id"
                                        @click="selectOrganization(org)"
                                        class="card border-0 shadow-sm rounded-3 p-3 bg-white position-relative transition-all"
                                        :class="{ 'border-start border-primary border-4 shadow-md bg-light-subtle': selectedOrg?.id === org.id }"
                                        style="cursor: pointer;"
                                    >
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div>
                                                <h5 class="h6 fw-bold text-dark mb-1 text-truncate" style="max-width: 200px;">
                                                    {{ org.name || 'Загрузка названия...' }}
                                                </h5>
                                                <span v-if="org.rating" class="badge bg-warning text-dark px-2 py-1" style="font-size: 11px;">
                                                      ⭐ {{ org.rating }}
                                                    </span>
                                            </div>

                                            <div class="text-end text-muted small lh-sm">
                                                <div class="fw-bold text-dark" style="font-size: 13px;">{{ org.reviews_count || 0 }}</div>
                                                <div style="font-size: 10px;">отзывов</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- ================= СПРАВА КОЛОНКА: ДЕТАЛИ И ЛЕНТА ОТЗЫВОВ ================= -->
                        <div class="col-12 col-lg-8">
                            <div v-if="selectedOrg" class="d-flex flex-column gap-4">

                                <!-- Главная плашка выбранной организации -->
                                <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
                                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">

                                        <!-- Левая часть: Название и ссылка -->
                                        <div class="flex-grow-1">
                                            <h2 class="h3 fw-bold text-dark mb-2" style="letter-spacing: -0.5px;">
                                                {{ selectedOrg.name }}
                                            </h2>
                                            <a :href="selectedOrg.yandex_url"
                                               target="_blank"
                                               class="d-inline-flex align-items-center gap-1 text-decoration-none text-primary fw-semibold small transition-all hover-opacity">
                                                <span>Открыть на Яндекс.Картах</span>
                                                <svg xmlns="http://w3.org" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                </svg>
                                            </a>
                                        </div>

                                        <!-- Правая часть: Рейтинг и статистика -->
                                        <div class="d-flex flex-column align-items-md-end gap-2 text-nowrap">

                                            <!-- Плашка рейтинга (мягкий премиальный оттенок золотого вместо кричащего желтого) -->
                                            <div v-if="selectedOrg.rating"
                                                 class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-3 fw-bold fs-5"
                                                 style="background-color: #FFF9E6; color: #DAA520; border: 1px solid #FFEAA7;">
                                                <span style="transform: translateY(-1px);">⭐</span>
                                                <span>{{ selectedOrg.rating }} <span class="fs-6 fw-normal opacity-75">/ 5.0</span></span>
                                            </div>

                                            <!-- Статистика (точка отображается, только если есть и оценки, и отзывы) -->
                                            <div v-if="selectedOrg.rating_count || selectedOrg.reviews_count"
                                                 class="d-flex align-items-center gap-2 text-secondary small fw-medium bg-light px-3 py-1.5 rounded-pill">
                                                <span v-if="selectedOrg.rating_count">📊 {{ selectedOrg.rating_count }} оценок</span>
                                                <span v-if="selectedOrg.rating_count && selectedOrg.reviews_count" class="text-black-50 opacity-50">•</span>
                                                <span v-if="selectedOrg.reviews_count">💬 {{ selectedOrg.reviews_count }} отзывов</span>
                                            </div>

                                        </div>

                                    </div>
                                </div>

                                <!-- Лента отзывов -->
                                <div v-for="review in selectedOrg.reviews.data"
                                     :key="review.id"
                                     class="review-card p-4 mb-1 bg-white rounded-3 shadow-sm border transition-all">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5 class="h6 fw-bold mb-0 text-dark">{{ review.author_name }}</h5>
                                        <div class="d-flex flex-column align-items-end gap-1 small text-muted">
                                                <span class="text-warning fw-bold fs-6">
                                                    {{ '★'.repeat(Math.round(review.stars || 0)) }}{{ '☆'.repeat(5 - Math.round(review.stars || 0)) }}
                                                </span>
                                            <span style="font-size: 11px;">{{ formatDate(review.published_at) }}</span>
                                        </div>
                                    </div>
                                    <p class="text-secondary small mb-0 text-break" style="white-space: pre-line; line-height: 1.6;">
                                        {{ review.text }}
                                    </p>
                                </div>

                                <!-- ПОСТРАНИЧНАЯ НАВИГАЦИЯ (PAGINATION) -->
                                <nav v-if="selectedOrg.reviews && selectedOrg.reviews.last_page > 1" class="d-flex justify-content-center mt-4">
                                    <ul class="pagination pagination-sm mb-0 gap-1">
                                        <li v-for="(link, index) in selectedOrg.reviews.links"
                                            :key="index"
                                            class="page-item"
                                            :class="{
                                                    'active': link.active,
                                                    'disabled': !link.url
                                                }">
                                            <button class="page-link rounded-2 border-0 fw-medium"
                                                    :class="link.active ? 'bg-dark text-white' : 'bg-light text-dark'"
                                                    @click="changePage(link.url)"
                                                    v-html="link.label">
                                            </button>
                                        </li>
                                    </ul>
                                </nav>

                            </div>

                            <!-- Заглушка, если список компаний пуст или ни одна компания не выбрана -->
                            <div v-else class="card border-0 shadow-sm rounded-3 p-5 bg-white text-center text-muted">
                                Выберите организацию в списке слева, чтобы просмотреть отзывы.
                            </div>
                        </div>

                    </div>
                </div>
        </main>
    </div>
</template>


<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

// Принимаем данные из Laravel
const props = defineProps({
    organizations: {
        type: Array,
        default: () => []
    },
    selectedOrg: {
        type: Object,
        default: () => null
    }
});

// Настройка реактивной формы
const form = useForm({
    yandex_url: '',
});

// Отправка формы на бэкенд Laravel
const submit = () => {
    form.post(route('organization.store'), {
        onSuccess: () => {
            form.reset();
        },
    });
};

// Выход из системы
const logout = () => {
    router.post(route('logout'));
};

// Метод переключения организации. Делаем Inertia-запрос с сохранением состояния скролла
const selectOrganization = (org) => {
    router.visit(route('dashboard'), {
        data: { org_id: org.id },
        preserveState: true,
        preserveScroll: true
    });
};

// Метод переключения страниц пагинации отзывов
const changePage = (url) => {
    if (!url) return;

    // Переходим по ссылке пагинации (Laravel сам сформирует её вид вроде /dashboard?org_id=5&page=2)
    router.visit(url, {
        preserveState: true,
        preserveScroll: true
    });
};

// Функция форматирования системных дат MySQL в красивый русский текст
const formatDate = (dateString) => {
    if (!dateString) return 'Дата не указана';
    const date = new Date(dateString.replace(' ', 'T'));
    return isNaN(date.getTime()) ? dateString : date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
};
</script>

<style scoped>
/* Стили для карточек организаций (слева) и отзывов (справа) */
.transition-all {
    transition: all 0.2s ease-in-out;
}

/* Ховер-эффект для интерактивных элементов */
.transition-all:hover {
    transform: translateY(-2px);
    box-shadow: 0 .5rem 1rem rgba(0,0,0,.1)!important;
}

/* Специфичный стиль для карточки отзыва */
.review-card {
    border: 1px solid rgba(0, 0, 0, 0.05); /* Очень нежная рамка, чтобы карточка не сливалась с общим фоном страницы */
}

.review-card:hover {
    border-color: rgba(0, 0, 0, 0.12); /* Чуть более заметная рамка при наведении */
}
</style>


