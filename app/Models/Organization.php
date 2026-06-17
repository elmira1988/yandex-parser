<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'yandex_url',
        'rating',
        'rating_count',
        'reviews_count'
    ];

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

}
