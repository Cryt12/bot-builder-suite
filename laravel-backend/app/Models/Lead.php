<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'chatbot_id',
        'conversation_id',
        'name',
        'email',
        'company',
        'phone',
        'status',
        'notes',
        'metadata',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
