<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrganizationRequest;
use App\Models\Organization;
use App\Services\YandexParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Validation\ValidationException;
class YandexController extends Controller
{
    protected $parser;

    public function __construct(YandexParserService $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Экран дашборда: получение организации и её отзывов
     */
    public function index(Request $request): \Inertia\Response
    {
        // 1. Получаем список всех организаций пользователя (без отзывов, только счетчик)
        $organizations = Auth::user()
            ->organizations()
            ->latest('id')
            ->get();

        // 2. Определяем, какая организация сейчас активна (из запроса или самая первая)
        $selectedId = $request->input('org_id') ?? $organizations->first()?->id;

        $selectedOrgWithReviews = null;

        if ($selectedId) {
            // Находим нужную компанию текущего пользователя
            $organization = Auth::user()->organizations()->find($selectedId);

            if ($organization) {
                // Пагинируем отзывы этой организации (по 10 штук на страницу)
                // Важно: Laravel добавит структуру пагинации внутрь связи reviews
                $organization->setRelation('reviews', $organization->reviews()->latest('published_at')->paginate(50)->withQueryString());
                $selectedOrgWithReviews = $organization;
            }
        }

        return Inertia::render('Dashboard', [
            'organizations' => $organizations,
            'selectedOrg' => $selectedOrgWithReviews, // Передаем отдельно готовую компанию с пагинацией внутри
        ]);
    }

    public function store(StoreOrganizationRequest $request)
    {
        // Получаем данные, которые уже успешно прошли синтаксическую проверку в FormRequest
        $validated = $request->validated();

        try {
            // 1. Запускаем парсер: он развернет короткую ссылку и вернет отзывы
            $parsedData = $this->parser->parse($validated['yandex_url']);

            // 2. Создаем запись организации в базе данных для текущего пользователя
            $organization = Organization::create([
                'user_id'    => Auth::id(),
                'name'       => $parsedData['organization']['name'],
                'yandex_url' => $validated['yandex_url'],
                'rating'     => $parsedData['organization']['rating'],
                'rating_count' => $parsedData['organization']['rating_count'],
                'reviews_count' => $parsedData['organization']['reviews_count'],
            ]);

            // 3. Сохраняем все полученные от Яндекса отзывы, привязывая их к организации
            foreach ($parsedData['reviews'] as $review) {
                $organization->reviews()->create($review);
            }

            // Перенаправляем обратно на дашборд. Inertia автоматически обновит состояние Vue
            return redirect()->route('dashboard');

        } catch (\Exception $e) {
            // Если в процессе парсинга (на втором этапе) возникла ошибка
            // (например, нет отзывов или битая ссылка), возвращаем её как ошибку валидации поля
            throw ValidationException::withMessages([
                'yandex_url' => $e->getMessage()
            ]);
        }
    }

}
