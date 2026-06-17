<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    /**
     * Разрешить ли пользователю выполнять этот запрос.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Правила валидации для полей формы.
     */
    public function rules(): array
    {
        return [
            'yandex_url' => [
                'required',
                'url',
                // 🌟 Проверяем наличие паттерна /-/ который Яндекс использует исключительно для коротких ссылок
                'regex:/yandex\.(ru|com)\/maps\/-\/[A-Za-z0-9_-]+/i'
            ]
        ];
    }

    /**
     * Кастомные сообщения об ошибках на русском языке.
     */
    public function messages(): array
    {
        return [
            'yandex_url.required' => 'Пожалуйста, введите ссылку на организацию.',
            'yandex_url.url' => 'Введите корректный URL-адрес.',
            // Четкое объяснение для пользователя, какую именно ссылку от него ждут
            'yandex_url.regex' => 'Необходимо скопировать именно короткую ссылку. Нажмите на карточке организации кнопку «Поделиться» и скопируйте полученный адрес.'
        ];
    }
}
