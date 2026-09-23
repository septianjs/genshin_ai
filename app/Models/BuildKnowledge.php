<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildKnowledge extends Model
{
    use HasFactory;

    protected $table = 'build_knowledge';

    protected $fillable = [
        'character_id',
        'category',
        'title',
        'content',
        'target_content',
        'embedding',
        'patch_version',
    ];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
