<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Organization;

class Review extends Model
{
    use HasFactory;

    /**
     * Разрешаем массовое заполнение для всех полей отзыва
     */
    protected $fillable = [
        'organization_id',
        'author_name', // 🌟 Убедитесь, что это поле теперь здесь!
        'text',
        'stars',
        'published_at',
    ];

    /**
     * Обратная связь с организацией (Отзыв принадлежит организации)
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
