<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use HeadlessChromium\BrowserFactory;

class YandexParserService
{
    /**
     * Извлекает ID организации из ссылки на Яндекс.Карты
     */
    public function extractOrgId(string &$url): ?string
    {
        // 1. Ссылка ВСЕГДА короткая — разворачиваем её через HEAD-запрос без лишних проверок
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ])
                ->withOptions([
                    'allow_redirects' => ['track_redirects' => true]
                ])
                ->head($url);

            // Получаем финальный длинный URL из истории редиректов Guzzle
            $history = $response->header('X-Guzzle-Redirect-History');
            if (!empty($history)) {
                $url = is_array($history) ? end($history) : $history;
            } else {
                $url = $response->effectiveUri()->__toString();
            }
        } catch (\Exception $e) {
            Log::error("Ошибка разворота короткой ссылки: " . $e->getMessage());
            return null;
        }

        // 2. Ищем числовой ID организации в полученной длинной ссылке
        $orgId = null;
        if (preg_match('/\/org\/(?:[^\/]+\/)?([0-9]+)/i', $url, $matches)) {
            $orgId = $matches[1];
        } elseif (preg_match('/orgpage\[id\]=([0-9]+)/i', $url, $matches)) {
            $orgId = $matches[1];
        }

        // 3. СБОРКА ИДЕАЛЬНОЙ ССЫЛКИ НА ОТЗЫВЫ
        if ($orgId) {
            $parsedUrl = parse_url($url);
            $existingParams = [];
            if (!empty($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $existingParams);
            }

            // Принудительно выставляем вкладку отзывов
            $existingParams['tab'] = 'reviews';

            // Формируем чистый URL
            if (preg_match('/(https:\/\/yandex\.(?:ru|com)\/maps\/org\/[^\/]+\/[0-9]+)/i', $url, $urlMatches)) {
                $basePathWithId = rtrim($urlMatches[1], '/');
                $url = $basePathWithId . '/reviews/?' . http_build_query($existingParams);
            } else {
                $url = "https://yandex.ru{$orgId}/reviews/?" . http_build_query($existingParams);
            }

            Log::info("Ссылка с отзывами: " . $url);
            return $orgId;
        }

        return null;
    }


    public function parse(string &$url): array {
        // Отключаем лимит времени выполнения самого PHP-скрипта
        set_time_limit(0);

        $orgId = $this->extractOrgId($url);
        if (!$orgId) {
            throw new \Exception('Не удалось извлечь ID организации из ссылки.');
        }

        // Настройка фабрики Chromium
        $browserFactory = new BrowserFactory('/usr/bin/chromium-browser');
        $browserFactory->setOptions([
            'connectionDelay' => 0,
            'startupTimeout' => 45,
            'sendSyncAndReceiveTimeout' => 45000,
        ]);

        $browser = $browserFactory->createBrowser([
            'headless' => true,
            'noSandbox' => true,
            'disableSetuidSandbox' => true,
            'customFlags' => [
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--blink-settings=imagesEnabled=false', // Отключаем картинки для скорости
                '--disable-remote-fonts',              // Отключаем шрифты
                '--disable-blink-features=AutomationControlled',
                '--window-size=900,1080',
                '--lang=ru-RU,ru',
            ]
        ]);

        try {
            $page = $browser->createPage();

            // Стелс-маскировка от антифрода Яндекса перед загрузкой страницы
            $page->addPreScript("
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
            Object.defineProperty(navigator, 'languages', { get: () => ['ru-RU', 'ru', 'en-US', 'en'] });
            Object.defineProperty(navigator, 'platform', { get: () => 'Win32' });
        ");

            // Массив из 10 актуальных десктопных User-Agent (без мобильных версий)
            $desktopUserAgents = [
                // 1. Google Chrome — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',

                // 2. Mozilla Firefox — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0',

                // 3. Microsoft Edge — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0',

                // 4. Apple Safari — macOS (Sequoia / Sonoma)
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.4 Safari/605.1.15',

                // 5. Google Chrome — macOS
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',

                // 6. Mozilla Firefox — Ubuntu Linux
                'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:145.0) Gecko/20100101 Firefox/145.0',

                // 7. Google Chrome — Linux x64
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',

                // 8. Opera — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 OPR/121.0.0.0',

                // 9. Яндекс Браузер (Десктопный) — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 YaBrowser/26.2.0.0 Yowser/2.5 Safari/537.36',

                // 10. Google Chrome — Windows 10 (стабильная классика)
                'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36'
            ];

            // Выбираем случайный десктопный User-Agent
            $randomUserAgent = $desktopUserAgents[array_rand($desktopUserAgents)];

            // Устанавливаем в страницу
            $page->setUserAgent($randomUserAgent);

            Log::info("Открываем страницу организации...");
            $navigation = $page->navigate($url);
            $navigation->waitForNavigation(\HeadlessChromium\Page::DOM_CONTENT_LOADED, 30000);

            // Даем 4 секунды на полную стабилизацию карты
            usleep(4000000);
            Log::info("Ожидаем появления блока с отзывами на странице...");

            try {
                // Ждем появление контейнера отзывов (максимум 15 секунд)
                $page->waitUntilContainsElement('.business-reviews-card-view', 15000);
                Log::info("Блок отзывов успешно обнаружен.");
            } catch (\Throwable $e) {
                Log::error("Ошибка при ожидании отзывов. Текст: " . $e->getMessage());
                throw new \Exception('Яндекс ограничил доступ или изменилась верстка. Сообщите техподдержке.');
            }

            // Фиксируем итоговые данные страницы
            $finalUrl = $page->evaluate("window.location.href")->getReturnValue();
            $finalTitle = $page->evaluate("document.title")->getReturnValue();
            Log::info("=== РЕЗУЛЬТАТ ТЕСТА ОТКРЫТИЯ ===");
            Log::info("Итоговый URL страницы: " . $finalUrl);
            Log::info("Заголовок (Title) страницы: " . $finalTitle);
            Log::info("=================================");

// --- ИСПРАВЛЕННЫЙ БЛОК ОЖИДАНИЯ ДЛЯ CHROME-PHP ---

            try {
                Log::info("Ожидаем рендеринга заголовка H1 и карточек отзывов...");

                // 1. Ждем появление заголовка организации H1 (максимум 20 секунд)
                $page->waitUntilContainsElement('h1', 20000);

                // 2. Ждем появление хотя бы одного физического отзыва (максимум 20 секунд)
                $page->waitUntilContainsElement('.business-review-view', 20000);

                Log::info("Элементы DOM успешно дорендерились.");
            } catch (\Throwable $e) {
                Log::error("Ошибка ожидания элементов DOM перед evaluate: " . $e->getMessage());
            }

// 3. Железная микро-пауза, чтобы тяжелые JS-скрипты Яндекса полностью ожили в Docker
            usleep(2500000);

            //МГНОВЕННЫЙ СБОР ВСЕХ ДАННЫХ ИЗ DOM ЗА ОДИН ШАГ
            Log::info(" Собираем текстовые отзывы из DOM дерева...");
            $evaluation = $page->evaluate("(() => {
                  // 1. Сначала ищем главный контейнер сводного рейтинга организации
                  const summaryContainer = document.querySelector('.business-summary-rating') ||
                                           document.querySelector('[data-chunk=\"reviews\"]');

                  // Ищем узлы строго внутри главного контейнера, чтобы не зацепить разметку из чужих отзывов
                  const nameNode = document.querySelector('[itemprop=\"name\"]') || document.querySelector('h1');

                  const ratingNode = summaryContainer ? summaryContainer.querySelector('[itemprop=\"ratingValue\"]') : null;
                  const ratingCountNode = summaryContainer ? summaryContainer.querySelector('[itemprop=\"ratingCount\"]') : null;
                  const ratingCountTextNode = summaryContainer ? summaryContainer.querySelector('.business-summary-rating-badge-view__rating-count') : null;

                  const reviewsCountNode = document.querySelector('.tabs-select-view__title._name_reviews .tabs-select-view__counter') ||
                                           (summaryContainer ? summaryContainer.querySelector('[itemprop=\"reviewCount\"]') : null);

                  const name = nameNode ? nameNode.innerText.trim() : 'Организация на Яндексе';

                  // Извлекаем количество оценок
                  let ratingCount = null;
                  if (ratingCountNode) {
                    ratingCount = parseInt(ratingCountNode.getAttribute('content'), 10);
                  } else if (ratingCountTextNode) {
                    const match = ratingCountTextNode.innerText.replace(/\s+/g, '').match(/\d+/);
                    ratingCount = match ? parseInt(match, 10) : null;
                  }

                  // Извлекаем количество отзывов
                  let reviewsCount = null;
                  if (reviewsCountNode) {
                    const rawReviews = reviewsCountNode.getAttribute('content') || reviewsCountNode.innerText;
                    if (rawReviews) {
                      reviewsCount = parseInt(rawReviews.replace(/\s+/g, ''), 10);
                    }
                  }

                  // КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ: Если оценок нет, или их количество равно 0/null, рейтинг ОБЯЗАН быть null
                  let rating = null;
                  if (ratingCount && ratingCount > 0 && ratingNode) {
                    const rawRating = ratingNode.getAttribute('content') || ratingNode.innerText;
                    rating = rawRating ? parseFloat(rawRating.replace(',', '.')) : null;
                  }

                  // Чистим нули в null для консистентности данных
                  if (ratingCount === 0) ratingCount = null;
                  if (reviewsCount === 0) reviewsCount = null;

                  // 2. Сбор карточек отзывов
                  const reviewNodes = Array.from(document.getElementsByClassName('business-review-view')).slice(0, 50);
                  const reviews = [];

                  for (let i = 0; i < reviewNodes.length; i++) {
                    const node = reviewNodes[i];
                    const authorNode = node.querySelector('.business-review-view__author-name');
                    const textNode = node.querySelector('.business-review-view__body') ||
                                     node.querySelector('.business-review-view__body-text') ||
                                     node.querySelector('.comment-view__text');
                    const starsContainer = node.querySelector('.business-rating-badge-view__stars');
                    const dateMeta = node.querySelector('meta[itemprop=\"datePublished\"]');

                    let rawDate = dateMeta ? dateMeta.getAttribute('content') : null;
                    let formattedDate = null;

                    if (rawDate) {
                      try {
                        formattedDate = new Date(rawDate).toISOString().slice(0, 19).replace('T', ' ');
                      } catch (e) {
                        formattedDate = null;
                      }
                    }

                    let starsCount = null;
                    if (starsContainer && starsContainer.getAttribute('aria-label')) {
                      const match = starsContainer.getAttribute('aria-label').match(/\d+/);
                      starsCount = match ? parseInt(match, 10) : null;
                    }

                    reviews.push({
                      author_name: authorNode ? authorNode.innerText.trim() : 'Аноним',
                      text: textNode ? textNode.innerText.trim() : 'Пользователь оставил только оценку, без текстового комментария.',
                      stars: starsCount,
                      published_at: formattedDate
                    });
                  }

                  return {
                    name,
                    rating,
                    rating_count: ratingCount,
                    reviews_count: reviewsCount,
                    reviews
                  };
                })()
                ", 30000);

            $result = $evaluation->getReturnValue();

            // 🌟 ЛОГИРУЕМ ОБЪЕМ СОБРАННЫХ ДАННЫХ
            Log::info("=== ПРОВЕРКА РЕАЛЬНОГО ОБЪЕМА ===");
            Log::info("Браузер физически нашел в DOM отзывов: " . count($result['reviews'] ?? []));
            Log::info("Всего отзывов: " . $result['reviews_count']);
            Log::info("Рейтинг: " . $result['rating']);
            Log::info("=================================");

            $firstReviews = [];
            $items = $result['reviews'] ?? [];
            foreach ($items as $item) {
                $firstReviews[] = [
                    'author_name'  => $item['author_name'],
                    'text'         => $item['text'],
                    'stars'        => $item['stars'],
                    'published_at' => $item['published_at']
                        ? \Illuminate\Support\Carbon::parse($item['published_at'])->format('Y-m-d H:i:s')
                        : now()->format('Y-m-d H:i:s'),
                ];
            }

            $finalReviews=[];

            if($result['reviews_count']>50){
                Log::info("Вытаскиваем ссылки");
                    //Нужно получить ссылки
                    //🌟Ждем выполнения Promise внутри JS
                    $rawFoundUrls = $page->evaluate("(() => {
                    return new Promise((resolve) => {
                        const foundUrls = [];
                        const originalFetch = window.fetch;

                        // Желаемый лимит (для организаций, где отзывов ОЧЕНЬ много)
                        const TARGET_COUNT = 11;

                        window.fetch = async function(...args) {
                            const firstArg = args[0];
                            let requestUrl = '';

                            if (typeof firstArg === 'object' && firstArg !== null) {
                                requestUrl = firstArg.url || '';
                            } else {
                                requestUrl = String(firstArg || '');
                            }

                            if (requestUrl.includes('fetchReviews')) {
                                if (!requestUrl.startsWith('http')) {
                                    requestUrl = window.location.origin + requestUrl;
                                }

                                if (!foundUrls.includes(requestUrl)) {
                                    foundUrls.push(requestUrl);
                                }

                                // Если достигли цели (например, 11 ссылок) — выходим сразу
                                if (foundUrls.length >= TARGET_COUNT) {
                                    if (window._scrollInterval) clearInterval(window._scrollInterval);
                                    window.fetch = originalFetch;
                                    resolve(foundUrls);
                                }
                            }
                            return originalFetch.apply(this, args);
                        };

                        const parent = document.querySelector('.scroll._width_wide') || document.querySelector('.scroll._width_narrow');
                        if (!parent) {
                            window.fetch = originalFetch;
                            resolve('Ошибка: Контейнер .scroll._width_wide не найден в DOM');
                            return;
                        }

                        const scrollableInner = Array.from(parent.querySelectorAll('*')).find(el => el.scrollHeight > el.clientHeight);
                        if (!scrollableInner) {
                            window.fetch = originalFetch;
                            resolve('Ошибка: Внутренний скролл-блок не найден');
                            return;
                        }

                        let totalScrolled = 0;
                        let lastScrollHeight = scrollableInner.scrollHeight;
                        let unchangedHeightCount = 0;

                        window._scrollInterval = setInterval(() => {
                            scrollableInner.scrollBy({ top: 2500, behavior: 'instant' });
                            totalScrolled += 2500;

                            // Умная проверка: изменилась ли высота контейнера после скролла?
                            if (scrollableInner.scrollHeight === lastScrollHeight) {
                                unchangedHeightCount++; // Если высота не растет, возможно, мы в самом низу
                            } else {
                                unchangedHeightCount = 0; // Сброс счетчика, если DOM подгрузился и вырос
                                lastScrollHeight = scrollableInner.scrollHeight;
                            }

                            // Условия остановки, если ссылок МЕНЬШЕ 11:
                            // 1. Мы 8 раз подряд скроллили, а высота страницы не увеличилась (достигнут конец)
                            // 2. Либо мы просто прокрутили слишком много (защитный лимит)
                            if (unchangedHeightCount >= 8 || totalScrolled > 300000) {
                                clearInterval(window._scrollInterval);
                                window.fetch = originalFetch;

                                // КРИТИЧЕСКИЙ ВАЖНЫЙ ИСПРАВЛЕННЫЙ МОМЕНТ:
                                // Возвращаем в PHP те ссылки, которые успели поймать (даже если их всего 2 или 3)
                                resolve(foundUrls);
                            }
                        }, 300);
                    });
                })()
                ")->getReturnValue(100000);
                    // Проверяем на текстовую ошибку из JS
                    if (is_string($rawFoundUrls) && str_starts_with($rawFoundUrls, 'Ошибка:')) {
                        throw new \Exception($rawFoundUrls);
                    }

                    // Превращаем результат гарантированно в массив
                    $urlsList = is_array($rawFoundUrls) ? $rawFoundUrls : [];

                    // 🌟 ЗАПИСЫВАЕМ ВСЕ ПОЙМАННЫЕ API URL В ЛОГИ LARAVEL С НОМЕРАМИ СТРАНИЦ
                    Log::info("=== ПЕРЕХВАЧЕННЫЙ НАБОР ССЫЛОК API (ВСЕГО: " . count($urlsList) . ") ===");
                    foreach ($urlsList as $index => $loggedUrl) {
                        $pageNumber = $index + 2; // Так как первая перехваченная ссылка — это page=2
                        Log::info("Страница №{$pageNumber}: " . $loggedUrl);
                    }
                    Log::info("=========================================");

                    $foundUrl = isset($urlsList[0]) ? (string)$urlsList[0] : '';


                    if (empty($foundUrl)) {
                        Log::info('Не удалось перехватить ни одной ссылки fetchReviews');
                    }
                    else{
                        // Проверяем статус первой ссылки перед работой
                        if (str_starts_with($foundUrl, 'Ошибка:')) {
                            throw new \Exception($foundUrl);
                        }

                        // Вытаскиваем авторизованные Cookies прямо из вкладки браузера
                        $cookies = $page->getCookies();
                        $cookieString = '';
                        foreach ($cookies as $cookie) {
                            $cookieString .= $cookie['name'] . '=' . $cookie['value'] . '; ';
                        }

                        // СТАРТ ЦИКЛА ПЕРЕБОРА: Проходим по всем ссылкам
                        foreach ($urlsList as $index => $currentPageUrl) {

                            Log::info("[Парсер] Скачиваем JSON контент по ПЕРВОЙ перехваченной ссылке...");

                            // Делаем легитимный cURL запрос
                            $ch = curl_init($currentPageUrl);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                                'Cookie: ' . rtrim($cookieString, '; '),
                                'Referer: https://yandex.ru/'
                            ]);

                            $jsonResponse = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);

                            //Проверяем, что ответил Яндекс
                            if ($httpCode === 200 && !empty($jsonResponse)) {
                                $decodedData = json_decode($jsonResponse, true);
                                Log::info("=== ПРОВЕРКА ОТВЕТА API ===");
                                Log::info("HTTP Код ответа: " . $httpCode);
                                Log::info("Кусочек JSON данных: " . mb_substr($jsonResponse, 0, 400) . "...");

                                if (isset($decodedData['data']['reviews'])) {
                                    Log::info("🔥 УСПЕХ! В ответе обнаружено отзывов: " . count($decodedData['data']['reviews']));

                                    $rawReviews = $decodedData['data']['reviews'];

                                    foreach ($rawReviews as $reviewIndex => $item) {
                                        // Форматируем дату из ISO 8601 в стандартный Y-m-d H:i:s для вашей БД
                                        $publishedAt = null;
                                        if (!empty($item['updatedTime'])) {
                                            try {
                                                $publishedAt = \Illuminate\Support\Carbon::parse($item['updatedTime'])
                                                    ->setTimezone(config('app.timezone'))
                                                    ->format('Y-m-d H:i:s');
                                            } catch (\Exception $e) {
                                                $publishedAt = \Illuminate\Support\Carbon::now()->format('Y-m-d H:i:s');
                                            }
                                        }

                                        $finalReviews[] = [
                                            'author_name'  => $item['author']['name'] ?? 'Аноним',
                                            'text'         => $item['text'] ?? 'Пользователь оставил только оценку.',
                                            'stars'        => $item['rating'] ?? 5,
                                            'published_at' => $publishedAt,
                                        ];

                                    }
                                }
                            } else {
                                Log::error("[Парсер] Ошибка запроса! HTTP Код: " . $httpCode . ", Ответ: " . $jsonResponse);
                            }
                        } // 🌟 КОНЕЦ ЦИКЛА ПЕРЕБОРА ССЫЛОК
                    }
            }

            // Обязательно закрываем сессию браузера
            $browser->close();
            // Финальный возврат сопоставленного массива данных
            return [
                'organization' => [
                    'id'            => $orgId,
                    'name'          => $result['name'],
                    'rating'        => $result['rating'],
                    'rating_count'  => $result['rating_count'],
                    'reviews_count' => $result['reviews_count'] ?? count($firstReviews),
                ],
                'reviews' => array_merge($firstReviews,$finalReviews),
            ];

        } catch (\Exception $e) {
            if (isset($browser)) {
                $browser->close();
            }
            throw new \Exception('Ошибка на этапе парсинга отзывов: ' . $e->getMessage().'Попробуйте отправить запрос заново.');
        }
    }

    /*
     * Перехватываем первые 11 fetch ссылок и парсим их
     * (12 ссылка выходит всегда с ошибкой  "code": 500,  "message": "Internal error in /business/fetchReviews"
     */

    public function parse_3(string $url): array {
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
            'startupTimeout' => 45,
            'sendSyncAndReceiveTimeout' => 45000,
        ]);

        $browser = $browserFactory->createBrowser([
            'headless' => true,
            'noSandbox' => true,
            'disableSetuidSandbox' => true,
            'customFlags' => [
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--blink-settings=imagesEnabled=false', // Отключаем картинки для скорости
                '--disable-remote-fonts',              // Отключаем шрифты
                '--disable-blink-features=AutomationControlled',
                '--window-size=1920,1080',
                '--lang=ru-RU,ru',
            ]
        ]);

        try {
            $page = $browser->createPage();

            // Стелс-маскировка от антифрода Яндекса перед загрузкой страницы
            $page->addPreScript("
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
            Object.defineProperty(navigator, 'languages', { get: () => ['ru-RU', 'ru', 'en-US', 'en'] });
            Object.defineProperty(navigator, 'platform', { get: () => 'Win32' });
        ");

            // Массив из 10 актуальных десктопных User-Agent (без мобильных версий)
            $desktopUserAgents = [
                // 1. Google Chrome — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',

                // 2. Mozilla Firefox — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0',

                // 3. Microsoft Edge — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0',

                // 4. Apple Safari — macOS (Sequoia / Sonoma)
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.4 Safari/605.1.15',

                // 5. Google Chrome — macOS
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',

                // 6. Mozilla Firefox — Ubuntu Linux
                'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:145.0) Gecko/20100101 Firefox/145.0',

                // 7. Google Chrome — Linux x64
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',

                // 8. Opera — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 OPR/121.0.0.0',

                // 9. Яндекс Браузер (Десктопный) — Windows 11
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 YaBrowser/26.2.0.0 Yowser/2.5 Safari/537.36',

                // 10. Google Chrome — Windows 10 (стабильная классика)
                'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36'
            ];

            // Выбираем случайный десктопный User-Agent
            $randomUserAgent = $desktopUserAgents[array_rand($desktopUserAgents)];

            // Устанавливаем в страницу
            $page->setUserAgent($randomUserAgent);

            Log::info("Открываем страницу организации...");
            $navigation = $page->navigate($url);
            $navigation->waitForNavigation(\HeadlessChromium\Page::DOM_CONTENT_LOADED, 30000);

            // Даем 4 секунды на полную стабилизацию карты
            usleep(4000000);

            Log::info("Запуск мягкого авто-скроллинга и перехвата API-ссылок...");


            // Инициализируем переменные для сбора ДО цикла, чтобы они вернулись в контроллер
            $finalReviews = [];

            //🌟 Считываем данные об организации
            Log::info(" Собираем текстовые отзывы из DOM дерева...");
            $first_data = $page->evaluate("(() => {
                // 1. Общие данные организации
                const nameNode = document.querySelector('[itemprop=\"name\"]') || document.querySelector('h1');
                const ratingNode = document.querySelector('[itemprop=\"ratingValue\"]');
                const ratingCountNode = document.querySelector('[itemprop=\"ratingCount\"]');
                const reviewsCountNode = document.querySelector('[itemprop=\"reviewCount\"]');

                const name = nameNode ? nameNode.innerText.trim() : 'Организация на Яндексе';
                const rating = ratingNode ? parseFloat(ratingNode.getAttribute('content') || ratingNode.innerText) : null;
                const ratingCount = ratingCountNode ? parseInt(ratingCountNode.getAttribute('content'), 10) : null;
                const reviewsCount = reviewsCountNode ? parseInt(reviewsCountNode.getAttribute('content'), 10) : null;

                // 2. Сбор карточек отзывов
                //const reviewNodes = document.getElementsByClassName('business-review-view');
                // Переводим коллекцию в массив и берем первые 50 элементов
                const reviewNodes = Array.from(document.getElementsByClassName('business-review-view')).slice(0, 50);

                const reviews = [];

                for (let i = 0; i < reviewNodes.length; i++) {
                    const node = reviewNodes[i];

                    const authorNode = node.querySelector('.business-review-view__author-name');
                    const textNode =node.querySelector('.business-review-view__body') || node.querySelector('.business-review-view__body-text') || node.querySelector('.comment-view__text');
                    const starsContainer = node.querySelector('.business-rating-badge-view__stars');
                    const dateMeta = node.querySelector('meta[itemprop=\"datePublished\"]');
                    let rawDate = dateMeta ? dateMeta.getAttribute('content') : null;
                    let formattedDate = null;
                    if (rawDate) {
                        // Превращаем строку ISO в объект даты, а затем в формат YYYY-MM-DD HH:MM:SS
                        formattedDate = new Date(rawDate).toISOString().slice(0, 19).replace('T', ' ');
                    }


                    let starsCount = 5;
                    if (starsContainer && starsContainer.getAttribute('aria-label')) {
                        const match = starsContainer.getAttribute('aria-label').match(/\\d+/);
                        starsCount = match ? parseInt(match[0], 10) : 5;
                    }

                    reviews.push({
                        author_name: authorNode ? authorNode.innerText.trim() : 'Аноним',
                        text: textNode ? textNode.innerText.trim() : 'Пользователь оставил только оценку, без текстового комментария.',
                        stars: starsCount,
                        published_at: formattedDate
                    });
                }

                return {
                    name,
                    rating,
                    rating_count: ratingCount,
                    reviews_count: reviewsCount,
                    reviews
                };
            })()", 30000);
            $result = $first_data->getReturnValue();

            Log::info(" Всего отзывов".$result['reviews_count']);
            if(count($result['reviews_count'])>50){
                //Нужно получить ссылки
                //🌟Ждем выполнения Promise внутри JS
                $rawFoundUrls = $page->evaluate("(() => {
                    return new Promise((resolve) => {
                        const foundUrls = [];
                        const originalFetch = window.fetch;

                        // Желаемый лимит (для организаций, где отзывов ОЧЕНЬ много)
                        const TARGET_COUNT = 11;

                        window.fetch = async function(...args) {
                            const firstArg = args[0];
                            let requestUrl = '';

                            if (typeof firstArg === 'object' && firstArg !== null) {
                                requestUrl = firstArg.url || '';
                            } else {
                                requestUrl = String(firstArg || '');
                            }

                            if (requestUrl.includes('fetchReviews')) {
                                if (!requestUrl.startsWith('http')) {
                                    requestUrl = window.location.origin + requestUrl;
                                }

                                if (!foundUrls.includes(requestUrl)) {
                                    foundUrls.push(requestUrl);
                                }

                                // Если достигли цели (например, 11 ссылок) — выходим сразу
                                if (foundUrls.length >= TARGET_COUNT) {
                                    if (window._scrollInterval) clearInterval(window._scrollInterval);
                                    window.fetch = originalFetch;
                                    resolve(foundUrls);
                                }
                            }
                            return originalFetch.apply(this, args);
                        };

                        const parent = document.querySelector('.scroll._width_wide');
                        if (!parent) {
                            window.fetch = originalFetch;
                            resolve('Ошибка: Контейнер .scroll._width_wide не найден в DOM');
                            return;
                        }

                        const scrollableInner = Array.from(parent.querySelectorAll('*')).find(el => el.scrollHeight > el.clientHeight);
                        if (!scrollableInner) {
                            window.fetch = originalFetch;
                            resolve('Ошибка: Внутренний скролл-блок не найден');
                            return;
                        }

                        let totalScrolled = 0;
                        let lastScrollHeight = scrollableInner.scrollHeight;
                        let unchangedHeightCount = 0;

                        window._scrollInterval = setInterval(() => {
                            scrollableInner.scrollBy({ top: 2500, behavior: 'instant' });
                            totalScrolled += 2500;

                            // Умная проверка: изменилась ли высота контейнера после скролла?
                            if (scrollableInner.scrollHeight === lastScrollHeight) {
                                unchangedHeightCount++; // Если высота не растет, возможно, мы в самом низу
                            } else {
                                unchangedHeightCount = 0; // Сброс счетчика, если DOM подгрузился и вырос
                                lastScrollHeight = scrollableInner.scrollHeight;
                            }

                            // Условия остановки, если ссылок МЕНЬШЕ 11:
                            // 1. Мы 8 раз подряд скроллили, а высота страницы не увеличилась (достигнут конец)
                            // 2. Либо мы просто прокрутили слишком много (защитный лимит)
                            if (unchangedHeightCount >= 8 || totalScrolled > 300000) {
                                clearInterval(window._scrollInterval);
                                window.fetch = originalFetch;

                                // КРИТИЧЕСКИЙ ВАЖНЫЙ ИСПРАВЛЕННЫЙ МОМЕНТ:
                                // Возвращаем в PHP те ссылки, которые успели поймать (даже если их всего 2 или 3)
                                resolve(foundUrls);
                            }
                        }, 300);
                    });
                })()
                ")->getReturnValue(100000);
                // Проверяем на текстовую ошибку из JS
                if (is_string($rawFoundUrls) && str_starts_with($rawFoundUrls, 'Ошибка:')) {
                    throw new \Exception($rawFoundUrls);
                }

                // Превращаем результат гарантированно в массив
                $urlsList = is_array($rawFoundUrls) ? $rawFoundUrls : [];

                // 🌟 ЗАПИСЫВАЕМ ВСЕ ПОЙМАННЫЕ API URL В ЛОГИ LARAVEL С НОМЕРАМИ СТРАНИЦ
                Log::info("=== ПЕРЕХВАЧЕННЫЙ НАБОР ССЫЛОК API (ВСЕГО: " . count($urlsList) . ") ===");
                foreach ($urlsList as $index => $loggedUrl) {
                    $pageNumber = $index + 2; // Так как первая перехваченная ссылка — это page=2
                    Log::info("Страница №{$pageNumber}: " . $loggedUrl);
                }
                Log::info("=========================================");

                $foundUrl = isset($urlsList[0]) ? (string)$urlsList[0] : '';


                if (empty($foundUrl)) {
                    Log::info('Не удалось перехватить ни одной ссылки fetchReviews');
                }
                else{
                    // Проверяем статус первой ссылки перед работой
                    if (str_starts_with($foundUrl, 'Ошибка:')) {
                        throw new \Exception($foundUrl);
                    }

                    // Вытаскиваем авторизованные Cookies прямо из вкладки браузера
                    $cookies = $page->getCookies();
                    $cookieString = '';
                    foreach ($cookies as $cookie) {
                        $cookieString .= $cookie['name'] . '=' . $cookie['value'] . '; ';
                    }



                    // СТАРТ ЦИКЛА ПЕРЕБОРА: Проходим по всем ссылкам
                    foreach ($urlsList as $index => $currentPageUrl) {

                        Log::info("[Парсер] Скачиваем JSON контент по ПЕРВОЙ перехваченной ссылке...");

                        // Делаем легитимный cURL запрос
                        $ch = curl_init($currentPageUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                            'Cookie: ' . rtrim($cookieString, '; '),
                            'Referer: https://yandex.ru/'
                        ]);

                        $jsonResponse = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        //Проверяем, что ответил Яндекс
                        if ($httpCode === 200 && !empty($jsonResponse)) {
                            $decodedData = json_decode($jsonResponse, true);
                            Log::info("=== ПРОВЕРКА ОТВЕТА API ===");
                            Log::info("HTTP Код ответа: " . $httpCode);
                            Log::info("Кусочек JSON данных: " . mb_substr($jsonResponse, 0, 400) . "...");

                            if (isset($decodedData['data']['reviews'])) {
                                Log::info("🔥 УСПЕХ! В ответе обнаружено отзывов: " . count($decodedData['data']['reviews']));

                                $rawReviews = $decodedData['data']['reviews'];

                                foreach ($rawReviews as $reviewIndex => $item) {
                                    // Форматируем дату из ISO 8601 в стандартный Y-m-d H:i:s для вашей БД
                                    $publishedAt = null;
                                    if (!empty($item['updatedTime'])) {
                                        try {
                                            $publishedAt = \Illuminate\Support\Carbon::parse($item['updatedTime'])
                                                ->setTimezone(config('app.timezone'))
                                                ->format('Y-m-d H:i:s');
                                        } catch (\Exception $e) {
                                            $publishedAt = \Illuminate\Support\Carbon::now()->format('Y-m-d H:i:s');
                                        }
                                    }

                                    $finalReviews[] = [
                                        'author_name'  => $item['author']['name'] ?? 'Аноним',
                                        'text'         => $item['text'] ?? 'Пользователь оставил только оценку.',
                                        'stars'        => $item['rating'] ?? 5,
                                        'published_at' => $publishedAt,
                                    ];

                                }
                            }
                        } else {
                            Log::error("[Парсер] Ошибка запроса! HTTP Код: " . $httpCode . ", Ответ: " . $jsonResponse);
                        }
                    } // 🌟 КОНЕЦ ЦИКЛА ПЕРЕБОРА ССЫЛОК
                }
            }

            $browser->close();

            return [
                'organization' => [
                    'id'            => $orgId,
                    'name'          => $result['name'],
                    'rating'        => $result['rating'],
                    'rating_count'  => $result['rating_count'],
                    'reviews_count' => $result['reviews_count'] ?? count($finalReviews),
                ],
                'reviews' => array_merge($result['reviews'],$finalReviews),
            ];

        } catch (\Exception $e) {
            if (isset($browser)) {
                $browser->close();
            }
            throw new \Exception('Ошибка парсинга: ' . $e->getMessage());
        }
    }

    /*
     *
     * Устанавливаем перехватчик сетевых запросов fetch
     * Берем пока только первую ссылку и парсим из Json строки отзывы и логируем их
     *
     */
    public function parse_2(string $url): array {
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
            'startupTimeout' => 45,
            'sendSyncAndReceiveTimeout' => 45000,
        ]);

        $browser = $browserFactory->createBrowser([
            'headless' => true,
            'noSandbox' => true,
            'disableSetuidSandbox' => true,
            'customFlags' => [
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--blink-settings=imagesEnabled=false', // Отключаем картинки для скорости
                '--disable-remote-fonts',              // Отключаем шрифты
                '--disable-blink-features=AutomationControlled',
                '--window-size=1920,1080',
                '--lang=ru-RU,ru',
            ]
        ]);

        try {
            $page = $browser->createPage();

            // Стелс-маскировка от антифрода Яндекса перед загрузкой страницы
            $page->addPreScript("
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
            Object.defineProperty(navigator, 'languages', { get: () => ['ru-RU', 'ru', 'en-US', 'en'] });
            Object.defineProperty(navigator, 'platform', { get: () => 'Win32' });
                ");

            $page->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');

            Log::info("[Парсер] Открываем страницу организации...");
            $navigation = $page->navigate($url);
            $navigation->waitForNavigation(\HeadlessChromium\Page::DOM_CONTENT_LOADED, 30000);

            // Даем 4 секунды на полную стабилизацию карты
            usleep(4000000);

            Log::info("[Парсер] Запуск мягкого авто-скроллинга и перехвата API-ссылки...");

            // Ждем выполнения Promise внутри JS (таймаут для PHP ставим 40 секунд)
            $rawFoundUrl = $page->evaluate("(() => {
            return new Promise((resolve) => {
                // Устанавливаем перехватчик сетевых запросов fetch
                const originalFetch = window.fetch;
                window.fetch = async function(...args) {
                    const firstArg = args[0];
                    let requestUrl = '';

                    // Надежно вытаскиваем строку URL, даже если Яндекс передал объект Request
                    if (typeof firstArg === 'object' && firstArg !== null) {
                        requestUrl = firstArg.url || '';
                    } else {
                        requestUrl = String(firstArg || '');
                    }

                    // Если поймали нужный запрос
                    if (requestUrl.includes('fetchReviews')) {
                        if (window._scrollInterval) clearInterval(window._scrollInterval);
                        window.fetch = originalFetch; // Восстанавливаем оригинальный fetch

                        // Если ссылка относительная, делаем её полной с доменом
                        if (!requestUrl.startsWith('http')) {
                            requestUrl = window.location.origin + requestUrl;
                        }

                        resolve(requestUrl); // Отправляем чистую строку в PHP
                    }
                    return originalFetch.apply(this, args);
                };

                // Ищем скролл-блок (используем ваш рабочий класс .scroll._width_wide)
                const parent = document.querySelector('.scroll._width_wide');
                if (!parent) {
                    resolve('Ошибка: Контейнер .scroll._width_wide не найден в DOM');
                    return;
                }

                const scrollableInner = Array.from(parent.querySelectorAll('*')).find(el => el.scrollHeight > el.clientHeight);
                if (!scrollableInner) {
                    resolve('Ошибка: Внутренний скролл-блок не найден');
                    return;
                }

                // Запускаем мягкий человеческий скроллинг малыми шагами
                let totalScrolled = 0;
                window._scrollInterval = setInterval(() => {
                    scrollableInner.scrollBy({ top: 400, behavior: 'smooth' });
                    totalScrolled += 400;

                    // Если прокрутили 35 000 пикселей, а ссылки нет (предохранитель)
                    if (totalScrolled > 35000) {
                        clearInterval(window._scrollInterval);
                        window.fetch = originalFetch;
                        resolve('Ошибка: Прокрутили до конца, но запрос fetchReviews не зафиксирован.');
                    }
                }, 500); // Интервал 500мс дает Яндексу время обрабатывать скролл и не злить защиту
            });
        })()")->getReturnValue(40000); // Ждем до 40 секунд на стороне PHP

            // Гарантируем, что результат — чистая строка
            $foundUrl = is_array($rawFoundUrl) ? (string)($rawFoundUrl[0] ?? '') : (string)$rawFoundUrl;

            // Записываем пойманный API URL в логи Laravel
            Log::info("=== ПЕРЕХВАЧЕННАЯ ССЫЛКА API ===");
            Log::info($foundUrl);
            Log::info("=================================");

            // 🌟 СКАЧИВАЕМ ДАННЫЕ С ИСПОЛЬЗОВАНИЕМ КУК ИЗ ТЕКУЩЕЙ СЕССИИ CHROMIUM
            Log::info("[Парсер] Скачиваем JSON контент по перехваченной ссылке...");

            // Вытаскиваем авторизованные Cookies прямо из вкладки браузера
            $cookies = $page->getCookies();
            $cookieString = '';
            foreach ($cookies as $cookie) {
                $cookieString .= $cookie['name'] . '=' . $cookie['value'] . '; ';
            }

            // Делаем легитимный запрос через cURL (или Guzzle), притворяясь этим же браузером
            $ch = curl_init($foundUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Cookie: ' . rtrim($cookieString, '; '),
                'Referer: https://yandex.ru/'
            ]);

            $jsonResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            //Проверяем, что ответил Яндекс
            if ($httpCode === 200 && !empty($jsonResponse)) { // Декодируем и пишем проверку в лог Laravel
                $decodedData = json_decode($jsonResponse, true);
                Log::info("=== ПРОВЕРКА ОТВЕТА API ===");
                Log::info("HTTP Код ответа: " . $httpCode);
                Log::info("Кусочек JSON данных: " . mb_substr($jsonResponse, 0, 400) . "...");

                //ОБЪЯВЛЯЕМ ПЕРЕМЕННЫЕ ДЛЯ СБОРА ДАННЫХ
                $finalReviews = [];
                $orgName = 'Организация на Яндексе';
                $orgRating = 5;
                $orgRatingCount = 0;

                if (isset($decodedData['data']['reviews'])) {
                    Log::info("🔥 УСПЕХ! В ответе обнаружено отзывов: " . count($decodedData['data']['reviews']));

                    // Извлекаем общие данные организации из API Яндекса
                    $orgName = $decodedData['data']['business']['name'] ?? 'Организация на Яндексе';
                    $orgRating = $decodedData['data']['business']['rating'] ?? 5;
                    $orgRatingCount = $decodedData['data']['business']['ratingCount'] ?? count($decodedData['data']['reviews']);

                    // 🌟 НАЧАЛО БЛОКА: Разбор и логирование полей каждого отзыва
                    $rawReviews = $decodedData['data']['reviews'];
                    \Illuminate\Support\Facades\Log::info("=== СПИСОК ОТЗЫВОВ ДЛЯ ТАБЛИЦЫ REVIEWS ===");

                    foreach ($rawReviews as $index => $item) {
                        // Форматируем дату из ISO 8601 в стандартный Y-m-d H:i:s для вашей БД
                        $publishedAt = null;
                        if (!empty($item['updatedTime'])) {
                            try {
                                $publishedAt = \Illuminate\Support\Carbon::parse($item['updatedTime'])
                                    ->setTimezone(config('app.timezone'))
                                    ->format('Y-m-d H:i:s');
                            } catch (\Exception $e) {
                                $publishedAt = \Illuminate\Support\Carbon::now()->format('Y-m-d H:i:s');
                            }
                        }

                        // 🌟 2. НАПОЛНЯЕМ МАССИВ ДЛЯ КОНТРОЛЛЕРА
                        $finalReviews[] = [
                            'author_name'  => $item['author']['name'] ?? 'Аноним',
                            'text'         => $item['text'] ?? 'Пользователь оставил только оценку.',
                            'stars'        => $item['rating'] ?? 5,
                            'published_at' => $publishedAt,
                        ];

                        $logNumber = $index + 1;
                        Log::info("Отзыв №{$logNumber}:");
                        Log::info(" [Автор]: " . ($item['author']['name'] ?? 'Аноним'));
                        Log::info(" [Дата публикации]: " . $publishedAt);
                        Log::info(" [Кол-во звезд]: " . ($item['rating'] ?? 5));
                        Log::info(" [Текст]: " . ($item['text'] ?? 'Пользователь оставил только оценку.'));
                        Log::info("-----------------------------------");
                    }
                    Log::info("=== КОНЕЦ СПИСКА ОТЗЫВОВ ===");
                    // 🌟 КОНЕЦ БЛОКА
                }
                Log::info("===========================");
            } else {
                Log::error("[Парсер] Ошибка запроса! HTTP Код: " . $httpCode . ", Ответ: " . $jsonResponse);
            }

            if (str_starts_with($foundUrl, 'Ошибка:')) {
                throw new \Exception($foundUrl);
            }

            // Закрываем браузер
            $browser->close();

            return [
                'organization' => [
                    'id'            => $orgId,
                    'name'          => $orgName ?? 'Ссылка успешно сохранена',
                    'rating'        => $orgRating ?? 5,
                    'rating_count'  => $orgRatingCount ?? 0,
                    'reviews_count' => $orgRatingCount ?? count($finalReviews),
                ],
                'reviews' => $finalReviews ?? [],
            ];


        } catch (\Exception $e) {
            if (isset($browser)) {
                $browser->close();
            }
            throw new \Exception('Ошибка парсинга: ' . $e->getMessage());
        }
    }

    /*
     *
     * Парсится первые 50 отзывов, делается только одна большая прокрутка по высоте блока с отзывами
     *
     */
    public function parse_1(string &$url): array {
        // Отключаем лимит времени выполнения самого PHP-скрипта
        set_time_limit(0);

        $orgId = $this->extractOrgId($url);
        if (!$orgId) {
            throw new \Exception('Не удалось извлечь ID организации из ссылки.');
        }

        // Настройка фабрики Chromium
        $browserFactory = new BrowserFactory('/usr/bin/chromium-browser');
        $browserFactory->setOptions([
            'connectionDelay' => 0,
            'startupTimeout' => 45,
            'sendSyncAndReceiveTimeout' => 45000,
        ]);

        $browser = $browserFactory->createBrowser([
            'headless' => true,
            'noSandbox' => true,
            'disableSetuidSandbox' => true,
            'customFlags' => [
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--blink-settings=imagesEnabled=false', // Отключаем картинки для скорости
                '--disable-remote-fonts',              // Отключаем шрифты
                '--disable-blink-features=AutomationControlled',
                '--window-size=1920,1080',
                '--lang=ru-RU,ru',
            ]
        ]);

        try {
            $page = $browser->createPage();
            $page->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');

            Log::info("Открываем URL организации...");

            // Просто переходим по ссылке. navigate() сам дождется базовой загрузки.
            $page->navigate($url);

            Log::info("Ожидаем появления блока с отзывами на странице...");

            try {
                // Ждем появление контейнера отзывов (максимум 15 секунд)
                $page->waitUntilContainsElement('.business-reviews-card-view', 15000);
                Log::info("Блок отзывов успешно обнаружен.");
            } catch (\Throwable $e) {
                Log::error("Ошибка при ожидании отзывов. Текст: " . $e->getMessage());
                throw new \Exception('Яндекс ограничил доступ или изменилась верстка. Сообщите техподдержке.');
            }

            // 3. Фиксируем итоговые данные страницы
            $finalUrl = $page->evaluate("window.location.href")->getReturnValue();
            $finalTitle = $page->evaluate("document.title")->getReturnValue();

            Log::info("=== РЕЗУЛЬТАТ ТЕСТА ОТКРЫТИЯ ===");
            Log::info("Итоговый URL страницы: " . $finalUrl);
            Log::info("Заголовок (Title) страницы: " . $finalTitle);
            Log::info("=================================");

            // ДИНАМИЧЕСКИЙ СБРОС НА ВЫСОТУ КОНТЕЙНЕРА
            Log::info("Выполняем глубокий прыжок прокрутки контента по актуальным блокам...");
            $page->evaluate("(() => {
                // Находим контентный блок, который задает общую высоту, и сам скролл-контейнер
                const contentBlock = document.querySelector('.scroll__content');
                const scrollContainer = document.querySelector('.scroll_width_wide') || document.querySelector('.scroll__container');

                if (contentBlock && scrollContainer) {
                    // Получаем точную текущую высоту всего контента с отзывами
                    const currentHeight = contentBlock.scrollHeight;
                    window.__lastChunkHeight = currentHeight;

                    // Скроллим контейнер напрямую в самую нижнюю точку (на высоту контента)
                    scrollContainer.scrollTop = currentHeight;

                } else if (scrollContainer) {
                    // Если контентный блок не нашелся, прыгаем вслепую по контейнеру
                    const fallbackHeight = scrollContainer.scrollHeight || 20000;
                    window.__lastChunkHeight = 'Блок scroll__content не найден. Высота: ' + fallbackHeight;
                    scrollContainer.scrollTop = fallbackHeight;
                } else {
                    window.__lastChunkHeight = 'Элементы для скролла не найдены';
                }
            })()", 10000);

            // Обязательно даем 2 секунды на стороне PHP, чтобы Яндекс успел подгрузить данные в DOM
            usleep(2000000);

            //МГНОВЕННЫЙ СБОР ВСЕХ ДАННЫХ ИЗ DOM ЗА ОДИН ШАГ
            Log::info(" Собираем текстовые отзывы из DOM дерева...");
            $evaluation = $page->evaluate("(() => {
                    // 1. Общие данные организации
                    const nameNode = document.querySelector('h1.orgpage-header-view__header') || document.querySelector('h1');
                    const name = nameNode ? nameNode.innerText.trim() : 'Организация на Яндексе';

                    const ratingNodes = document.querySelectorAll('.business-summary-rating-badge-view__rating-text');
                    const ratingStr = Array.from(ratingNodes).map(node => node.textContent.trim()).join('').replace(',', '.');
                    const rating = ratingStr ? parseFloat(ratingStr) : null;

                    const ratingCountNode = document.querySelector('.business-rating-amount-view');
                    const ratingCountMatch = ratingCountNode ? ratingCountNode.textContent.match(/\d+/) : null;
                    const ratingCount = ratingCountMatch ? parseInt(ratingCountMatch, 10) : null;
                    const reviewsCount = ratingCount;

                    // 2. Сбор всех подгруженных карточек отзывов (без ограничения slice)
                    const reviewNodes = Array.from(document.querySelectorAll('.business-reviews-card-view, .business-review-view'));
                    const reviews = [];

                    for (let i = 0; i < reviewNodes.length; i++) {
                        const node = reviewNodes[i];

                        const authorNode = node.querySelector('.business-review-view__author-name');
                        const textNode = node.querySelector('.business-review-view__body-text') ||
                                         node.querySelector('.business-review-view__body') ||
                                         node.querySelector('.comment-view__text');

                        const starsContainer = node.querySelector('.business-rating-badge-view__stars');
                        const dateNode = node.querySelector('.business-review-view__date');

                        let formattedDate = null;
                        if (dateNode) {
                            const metaDate = dateNode.querySelector('meta[itemprop=\"datePublished\"]');
                            const rawDate = metaDate ? metaDate.getAttribute('content') : null;
                            if (rawDate) {
                                try {
                                    formattedDate = new Date(rawDate).toISOString().slice(0, 19).replace('T', ' ');
                                } catch (e) {
                                    formattedDate = rawDate;
                                }
                            }
                        }

                        let starsCount = 5;
                        if (starsContainer && starsContainer.getAttribute('aria-label')) {
                            const match = starsContainer.getAttribute('aria-label').match(/\d+/);
                            starsCount = match ? parseInt(match, 10) : 5;
                        }

                        reviews.push({
                            author_name: authorNode ? authorNode.innerText.trim() : 'Аноним',
                            text: textNode ? textNode.innerText.trim() : 'Пользователь оставил только оценку, без текстового комментария.',
                            stars: starsCount,
                            published_at: formattedDate
                        });
                    }

                    // Возвращаем итоговый объект
                    return {
                        name,
                        rating,
                        rating_count: ratingCount,
                        reviews_count: reviewsCount,
                        reviews
                    };
                })()
", 30000);

            $result = $evaluation->getReturnValue();

            // Обязательно закрываем сессию браузера
            $browser->close();

            // 🌟 ЛОГИРУЕМ ОБЪЕМ СОБРАННЫХ ДАННЫХ
            Log::info("=== ПРОВЕРКА РЕАЛЬНОГО ОБЪЕМА ===");
            Log::info("Браузер физически нашел в DOM отзывов: " . count($result['reviews'] ?? []));
            Log::info("РЕЙТИНГ: " . $result['rating']);
            Log::info("=================================");

            // Преобразуем собранную пачку в формат Eloquent Laravel
            $finalReviews = [];
            $items = $result['reviews'] ?? [];
            foreach ($items as $item) {
                $finalReviews[] = [
                    'author_name'  => $item['author_name'],
                    'text'         => $item['text'],
                    'stars'        => $item['stars'],
                    'published_at' => $item['published_at']
                        ? \Illuminate\Support\Carbon::parse($item['published_at'])->format('Y-m-d H:i:s')
                        : now()->format('Y-m-d H:i:s'),
                ];
            }



            // Финальный возврат сопоставленного массива данных
            return [
                'organization' => [
                    'id'            => $orgId,
                    'name'          => $result['name'],
                    'rating'        => $result['rating'],
                    'rating_count'  => $result['rating_count'],
                    'reviews_count' => $result['reviews_count'] ?? count($finalReviews),
                ],
                'reviews' => $finalReviews,
            ];

        } catch (\Exception $e) {
            if (isset($browser)) {
                $browser->close();
            }
            throw new \Exception('Ошибка на этапе парсинга отзывов: ' . $e->getMessage().' или попробуйте отправить запрос позже заново.');
        }
    }








}
