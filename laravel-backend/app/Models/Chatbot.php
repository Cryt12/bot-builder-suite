<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Chatbot extends Model
{
    use HasFactory;
    use HasUuids;

    private static ?bool $hasPublicKeyColumn = null;
    private static ?bool $hasPublicRateLimitColumn = null;
    private static ?bool $hasWidgetCacheMinutesColumn = null;
    private static ?bool $hasLogoColumns = null;

    protected $fillable = [
        'user_id',
        'name',
        'welcome_message',
        'system_prompt',
        'primary_color',
        'logo_path',
        'logo_original_name',
        'bubble_position',
        'tone',
        'language',
        'collect_email',
        'api_key',
        'public_key',
        'allowed_domains',
        'public_rate_limit_per_minute',
        'widget_cache_minutes',
        'is_active',
        'llm_provider',
        'llm_model',
    ];

    protected function casts(): array
    {
        return [
            'collect_email' => 'boolean',
            'allowed_domains' => 'array',
            'public_rate_limit_per_minute' => 'integer',
            'widget_cache_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Chatbot $chatbot) {
            $chatbot->api_key ??= 'cb_' . Str::random(48);

            if (self::supportsPublicKey()) {
                $chatbot->public_key ??= 'pbk_' . Str::random(48);
            }

            if (self::supportsPublicRateLimit()) {
                $chatbot->public_rate_limit_per_minute ??= 60;
            }

            if (self::supportsWidgetCacheMinutes()) {
                $chatbot->widget_cache_minutes ??= 10;
            }

            // Default provider/model to system defaults
            $chatbot->llm_provider ??= config('models.llm.default_provider');
            $chatbot->llm_model ??= config("models.llm.{$chatbot->llm_provider}.model");
        });
    }

    public static function supportsPublicKey(): bool
    {
        return self::$hasPublicKeyColumn ??= Schema::hasColumn('chatbots', 'public_key');
    }

    public static function supportsPublicRateLimit(): bool
    {
        return self::$hasPublicRateLimitColumn ??= Schema::hasColumn('chatbots', 'public_rate_limit_per_minute');
    }

    public static function supportsWidgetCacheMinutes(): bool
    {
        return self::$hasWidgetCacheMinutesColumn ??= Schema::hasColumn('chatbots', 'widget_cache_minutes');
    }

    public static function supportsLogoUpload(): bool
    {
        if (self::$hasLogoColumns !== null) {
            return self::$hasLogoColumns;
        }

        return self::$hasLogoColumns = Schema::hasColumn('chatbots', 'logo_path')
            && Schema::hasColumn('chatbots', 'logo_original_name');
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
