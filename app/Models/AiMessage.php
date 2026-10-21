<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'tokens_used',
    ];

    protected function casts(): array
    {
        return [
            'tokens_used' => 'integer',
        ];
    }

    /**
     * Sesi percakapan induk pesan ini.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
