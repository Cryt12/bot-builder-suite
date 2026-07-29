<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value store for workspace-wide settings.
 *
 * Values are cached because the chat path reads model routing on every request;
 * writes bust the cache so a change takes effect on the next message, with no
 * deploy or restart.
 */
class AppSetting extends Model
{
    public const LLM_PRIMARY = 'llm.primary_provider';
    public const LLM_SECONDARY = 'llm.secondary_provider';

    /** Sentinel stored when an admin turns the backup off. */
    public const NONE = '';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    private const CACHE_PREFIX = 'app_setting:';

    public static function read(string $key, mixed $default = null): mixed
    {
        $cached = Cache::rememberForever(
            self::CACHE_PREFIX . $key,
            // Wrapped so a legitimately null value is still a cache hit.
            fn () => ['v' => static::query()->find($key)?->value],
        );

        return $cached['v'] ?? $default;
    }

    public static function write(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_PREFIX . $key);
    }

    /**
     * The model unpinned bots answer with. Falls back to the .env provider
     * default until an admin has saved a choice.
     *
     * @return array{key: string, provider: string, model: string, label: string, note: string}
     */
    public static function llmPrimaryEntry(): array
    {
        return static::resolveEntry(static::read(self::LLM_PRIMARY))
            ?? static::defaultEntryFor((string) config('models.llm.default_provider'));
    }

    /** The model retried when the primary fails, or null when failover is off. */
    public static function llmSecondaryEntry(): ?array
    {
        $stored = static::read(self::LLM_SECONDARY);

        // An empty string is an admin explicitly choosing "no backup"; null just
        // means nothing has been saved yet, so the .env default still applies.
        if ($stored === '') {
            return null;
        }

        if ($stored === null) {
            $provider = (string) config('models.llm.fallback_provider');

            return $provider === '' ? null : static::defaultEntryFor($provider);
        }

        return static::resolveEntry($stored);
    }

    /** @return array<string, array{key: string, provider: string, model: string, label: string, note: string}> */
    public static function catalog(): array
    {
        $out = [];

        foreach ((array) config('models.llm.catalog', []) as $key => $entry) {
            $out[$key] = ['key' => (string) $key] + $entry;
        }

        return $out;
    }

    private static function resolveEntry(mixed $stored): ?array
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $catalog = static::catalog();

        if (isset($catalog[$stored])) {
            return $catalog[$stored];
        }

        // Settings saved before routing became per-model held a bare provider
        // name; keep those working rather than silently resetting them.
        return static::defaultEntryFor($stored);
    }

    private static function defaultEntryFor(string $provider): array
    {
        foreach (static::catalog() as $entry) {
            if ($entry['provider'] === $provider) {
                return $entry;
            }
        }

        return [
            'key' => $provider,
            'provider' => $provider,
            'model' => (string) config("models.llm.{$provider}.model"),
            'label' => ucfirst($provider),
            'note' => '',
        ];
    }
}
