<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'title',
        'vision',
        'weapon_type',
        'rarity',
        'description',
        'skill_data',
        'constellation_data',
        'ascension_materials',
        'icon_url',
        'patch_version',
        'is_validated',
        'synced_at',
    ];

    protected $casts = [
        'skill_data' => 'array',
        'constellation_data' => 'array',
        'ascension_materials' => 'array',
        'is_validated' => 'boolean',
        'synced_at' => 'datetime',
        'rarity' => 'integer',
    ];

    /**
     * Relasi ke data theorycraft build knowledge.
     */
    public function buildKnowledge(): HasMany
    {
        return $this->hasMany(BuildKnowledge::class);
    }
}
