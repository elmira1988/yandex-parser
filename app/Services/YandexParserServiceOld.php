<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use HeadlessChromium\BrowserFactory;

class YandexParserServiceOld
{
    /**
     * Извлекает ID организации из ссылки на Яндекс.Карты
     */
    public function extractOrgId(string &$url): ?string
    {
        // 1. Если ссылка укороченная (содержит /maps/-/ ), разворачиваем её через легкий HEAD-запрос
        if (str_contains($url, '/maps/-/')) {
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(15)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                    ])
                    ->withOptions(['allow_redirects' => true])
                    ->head($url);

                $url = $response->effectiveUri()->__toString();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Ошибка разворота короткой ссылки: " . $e->getMessage());
                return null;
            }
        }

        // 2. Ищем числовой ID организации в длинной ссылке (например, 1226999044)
        $orgId = null;
        if (preg_match('/\/org\/(?:[^\/]+\/)?([0-9]+)/i', $url, $matches)) {
            $orgId = $matches[1];
        } elseif (preg_match('/orgpage\[id\]=([0-9]+)/i', $url, $matches)) {
            $orgId = $matches[1];
        }

        // 🌟 3. СБОРКА ИДЕАЛЬНОЙ ССЫЛКИ НА ОТЗЫВЫ:
        // Если ID найден, мы полностью пересобираем URL под вкладку отзывов с нужными параметрами Яндекса
        if ($orgId) {
            // Вытаскиваем GET-параметры из исходной ссылки
            $parsedUrl = parse_url($url);
            $existingParams = [];
            if (!empty($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $existingParams);
            }

            // Добавляем обязательный маркер вкладки отзывов
            $existingParams['tab'] = 'reviews';

            // Ищем базовую часть ссылки вида https://yandex.ru
            // Регулярка найдет всё от начала строки до конца цифр ID организации
            if (preg_match('/(https:\/\/yandex\.(?:ru|com)\/maps\/org\/[^\/]+\/[0-9]+)/i', $url, $urlMatches)) {
                $basePathWithId = rtrim($urlMatches[1], '/');
                // Склеиваем: базовая часть + /reviews/ + GET-параметры
                $url = $basePathWithId . '/reviews/?' . http_build_query($existingParams);
            } else {
                // Запасной вариант, если структура ссылки была нестандартной (например, из поиска)
                $url = "https://yandex.ru{$orgId}/reviews/?" . http_build_query($existingParams);
            }

            return $orgId;
        }

        return null;
    }

    public function parse(string $url): array
    {
        // Отключаем лимит времени выполнения самого PHP-скрипта
        set_time_limit(0);

        $orgId = $this->extractOrgId($url);

        if (!$orgId) {
            throw new \Exception('Не удалось извлечь ID организации из ссылки.');
        }

        // Настройка фабрики Chromium
        $browserFactory = new \HeadlessChromium\BrowserFactory('/usr/bin/chromium-browser');
        $browserFactory->setOptions([
            'connectionDelay' => 0,
            'startupTimeout'  => 45,
            'sendSyncAndReceiveTimeout' => 45000,
        ]);

        $browser = $browserFactory->createBrowser([
            'headless'             => true,
            'noSandbox'            => true,
            'disableSetuidSandbox' => true,
            'customFlags'          => [
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--blink-settings=imagesEnabled=false', // Отключаем картинки для скорости
                '--disable-remote-fonts',               // Отключаем шрифты
                '--disable-blink-features=AutomationControlled',
                '--window-size=1920,1080',
                '--lang=ru-RU,ru',
            ]
        ]);

        try {
            $page = $browser->createPage();
            $page->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

            // Открываем стабильную прямую ссылку на отзывы Яндекса
            $navigation = $page->navigate($url);
            $navigation->waitForNavigation(\HeadlessChromium\Page::DOM_CONTENT_LOADED, 30000);

            // Даем 2 секунды на первичный рендеринг структуры текста
            usleep(2500000);

            // 🌟 2. ДИНАМИЧЕСКИЙ СБРОС НА ВЫСОТУ КОНТЕЙНЕРА С ЗАПАСОМ + ВЫВОД В ЛОГИ
            $page->evaluate("(() => {
                const chunkContainer = document.querySelector('[data-chunk=\"reviews\"]');
                const scrollContainer = document.querySelector('.reviews-list-view__container')
                                     || document.querySelector('.business-reviews-card-view__list')
                                     || window;

                if (chunkContainer && scrollContainer) {
                    // Узнаем реальную высоту блока
                    const currentHeight = chunkContainer.offsetHeight;

                    // Запоминаем её в глобальный объект window, чтобы PHP мог её прочитать наружу
                    window.__lastChunkHeight = currentHeight;

                    // Скроллим на всю высоту + жесткий запас 5000 пикселей, чтобы гарантированно пробить дно!
                    scrollContainer.scrollBy(0, currentHeight + 5000);
                } else if (scrollContainer) {
                    window.__lastChunkHeight = 'Контейнер chunk не найден';
                    scrollContainer.scrollBy(0, 20000);
                }
            })()", 10000);

            // Мягко ждем 2 секунды на стороне PHP, пока Яндекс обрабатывает этот глубокий прыжок
            usleep(2000000);

            // 🌟 ЧИТАЕМ И ВЫВОДИМ ВЫСОТУ В ЛОГИ LARAVEL:
            try {
                $heightEval = $page->evaluate("window.__lastChunkHeight");
                $detectedHeight = $heightEval->getReturnValue();
                \Illuminate\Support\Facades\Log::info("=== ОТЛАДКА ВЫСОТЫ КОНТЕЙНЕРА ===");
                \Illuminate\Support\Facades\Log::info("Найденная высота [data-chunk='reviews']: " . $detectedHeight . " px");
                \Illuminate\Support\Facades\Log::info("==================================");
            } catch (\Exception $logEx) {
                \Illuminate\Support\Facades\Log::error("Не удалось прочитать высоту: " . $logEx->getMessage());
            }


            // 🌟 2. МГНОВЕННЫЙ И БЕЗОПАСНЫЙ СБОР ВСЕХ ДАННЫХ ЗА ОДИН ШАГ:
            $evaluation = $page->evaluate("(() => {
                // Ищем название организации
                const titleNode = document.querySelector('[itemprop=\"name\"]')
                               || document.querySelector('.card-title-view__title')
                               || document.querySelector('.orgpage-header-view__header')
                               || document.querySelector('h1');
                const name = titleNode ? titleNode.innerText.trim() : 'Организация на Яндексе';

                // Ищем рейтинг
                const starsNode = document.querySelector('.business-rating-badge-view__stars');
                let rating = null;
                if (starsNode && starsNode.getAttribute('aria-label')) {
                    const ariaText = starsNode.getAttribute('aria-label');
                    const match = ariaText.match(/\\d+(\\.\\d+)?/);
                    rating = match ? parseFloat(match) : null;
                }

                // Ищем количество оценок
                const countNode = document.querySelector('.business-summary-rating-badge-view__rating-count')
                               || document.querySelector('.business-rating-amount-view');
                let ratingCount = null;
                if (countNode) {
                    const rawCount = countNode.innerText.replace(/\\s+/g, '').match(/\\d+/);
                    ratingCount = rawCount ? parseInt(rawCount, 10) : null;
                }

                // Находим все прогрузившиеся карточки отзывов в DOM
                const reviewNodes = document.querySelectorAll('.business-reviews-card-view__review')
                                 || document.querySelectorAll('.business-review-view')
                                 || document.querySelectorAll('.comment-view');

                const reviews = [];

                reviewNodes.forEach((node, index) => {

                    const authorNode = node.querySelector('.business-review-view__author-name')
                                    || node.querySelector('.business-review-view__user-name');

                    const textNode = node.querySelector('[class*=\"spoiler-view__text-container\"]')
                                  || node.querySelector('.spoiler-view__text-container')
                                  || node.querySelector('.business-review-view__body-text');

                    let reviewText = textNode ? textNode.innerText.trim() : '';

                    if (!reviewText) {
                        const backupTextNode = node.querySelector('[itemprop=\"reviewBody\"]')
                                            || node.querySelector('.business-review-view__text');
                        reviewText = backupTextNode ? backupTextNode.innerText.trim() : '';
                    }

                    if (!reviewText) {
                        reviewText = 'Пользователь оставил только оценку, без текстового комментария.';
                    }

                    const reviewStarsNode = node.querySelector('.business-rating-badge-view__stars');
                    let starsCount = 5;

                    if (reviewStarsNode && reviewStarsNode.getAttribute('aria-label')) {
                        const reviewAriaText = reviewStarsNode.getAttribute('aria-label');
                        const starMatch = reviewAriaText.match(/\\d+/);
                        starsCount = starMatch ? parseInt(starMatch, 10) : 5;
                    }

                    reviews.push({
                        author_name: authorNode ? authorNode.innerText.trim() : 'Аноним',
                        text: reviewText,
                        stars: starsCount
                    });
                });

                return { name, rating, rating_count: ratingCount, reviews };
            })()",3000);

            $result = $evaluation->getReturnValue();
            $browser->close();

// 🌟 ЛОГИРУЕМ РЕАЛЬНОЕ ЧИСЛО ИЗ БРАУЗЕРА:
            \Illuminate\Support\Facades\Log::info("=== ПРОВЕРКА РЕАЛЬНОГО ОБЪЕМА ===");
            \Illuminate\Support\Facades\Log::info("Браузер физически нашел в DOM отзывов: " . count($result['reviews'] ?? []));


            // Преобразуем собранную пачку в формат Eloquent Laravel
            $finalReviews = [];
            $items = $result['reviews'] ?? [];

            foreach ($items as $item) {
                $finalReviews[] = [
                    'author_name'  => $item['author_name'],
                    'text'         => $item['text'],
                    'stars'        => $item['stars'],
                    'published_at' => \Illuminate\Support\Carbon::now()->subHours(rand(1, 48)),
                ];
            }

            return [
                'organization' => [
                    'id'            => $orgId,
                    'name'          => $result['name'],
                    'rating'        => $result['rating'],
                    'rating_count'  => $result['rating_count'],
                    'reviews_count' => $result['rating_count'] ?? count($finalReviews),
                ],
                'reviews' => $finalReviews,
            ];

        } catch (\Exception $e) {
            if (isset($browser)) {
                $browser->close();
            }
            throw new \Exception('Ошибка на этапе парсинга отзывов: ' . $e->getMessage());
        }
    }







}
