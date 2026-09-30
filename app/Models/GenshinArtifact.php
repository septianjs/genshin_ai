<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GenshinArtifact extends Model
{
    use HasFactory;

    protected $table = 'genshin_artifacts';

    protected $fillable = [
        'slug',
        'name',
        'rarity',
        'two_piece_bonus',
        'four_piece_bonus',
        'raw_data',
        'source',
        'patch_version',
        'is_validated',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'is_validated' => 'boolean',
    ];
}