<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GenshinWeapon extends Model
{
    use HasFactory;

    protected $table = 'genshin_weapons';

    protected $fillable = [
        'slug',
        'name',
        'weapon_type',
        'rarity',
        'base_attack',
        'secondary_stat',
        'passive_name',
        'passive_description',
        'raw_data',
        'source',
        'patch_version',
        'is_validated',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'is_validated' => 'boolean',
        'rarity' => 'integer',
    ];
}