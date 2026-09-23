<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_token',
        'title',
        'character_slug',
        'active_team',
        'target_content',
        'patch_version',
    ];

    protected $casts = [
        'active_team' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
