<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Chatbot extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'welcome_message',
        'system_prompt',
        'primary_color',
        'bubble_position',
        'tone',
        'language',
        'collect_email',
        'api_key',
        'allowed_domains',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'collect_email' => 'boolean',
            'allowed_domains' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Chatbot $chatbot) {
            $chatbot->api_key ??= 'cb_' . Str::random(48);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function documentChunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function analyticsEvents(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

}
