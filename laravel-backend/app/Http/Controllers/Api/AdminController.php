<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    /**
     * The provider options an admin can route between, plus the current choice.
     *
     * Each provider is configured with exactly one model, so picking a provider
     * is picking a model -- the label carries the model id so the admin sees
     * what they are actually selecting.
     */
    public function llmRouting(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->json([
            'options' => $this->modelOptions(),
            'primary' => AppSetting::llmPrimaryEntry()['key'],
            'secondary' => AppSetting::llmSecondaryEntry()['key'] ?? '',
        ]);
    }

    public function updateLlmRouting(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $available = array_keys(AppSetting::catalog());

        $data = $request->validate([
            'primary' => ['required', 'string', Rule::in($available)],
            // Laravel's ConvertEmptyStringsToNull turns "" into null before this
            // runs, so "no backup" arrives as null and is normalised below.
            'secondary' => ['present', 'nullable', 'string', Rule::in($available)],
        ]);

        $data['secondary'] = (string) ($data['secondary'] ?? '');

        abort_if(
            $data['secondary'] === $data['primary'],
            422,
            'The backup must be a different model from the main one.',
        );

        AppSetting::write(AppSetting::LLM_PRIMARY, $data['primary']);
        AppSetting::write(AppSetting::LLM_SECONDARY, $data['secondary']);

        return $this->json([
            'primary' => AppSetting::llmPrimaryEntry()['key'],
            'secondary' => AppSetting::llmSecondaryEntry()['key'] ?? '',
        ]);
    }

    /**
     * The catalog, annotated with whether each entry can actually be reached.
     *
     * @return array<int, array{value: string, label: string, model: string, provider: string, ready: bool, note: string}>
     */
    private function modelOptions(): array
    {
        $hasKey = (bool) (config('models.llm.openrouter.api_key') ?: config('services.openrouter.api_key'));

        return array_values(array_map(function (array $entry) use ($hasKey) {
            $ready = $entry['provider'] !== 'openrouter' || $hasKey;

            return [
                'value' => $entry['key'],
                'label' => $entry['label'],
                'model' => $entry['model'],
                'provider' => $entry['provider'],
                'ready' => $ready,
                'note' => $ready ? $entry['note'] : 'Needs an OpenRouter API key',
            ];
        }, AppSetting::catalog()));
    }

    public function users(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $users = User::query()
            ->with(['chatbots' => fn ($query) => $query->orderBy('name')])
            ->withCount('chatbots')
            ->orderBy('created_at')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'chatbots_count' => $user->chatbots_count,
                    'chatbots' => $user->chatbots->map(fn (Chatbot $bot) => [
                        'id' => $bot->id,
                        'name' => $bot->name,
                        'primary_color' => $bot->primary_color,
                        'is_active' => (bool) $bot->is_active,
                        'created_at' => $bot->created_at?->toIso8601String(),
                    ])->values(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return $this->json([
            'users' => $users,
            'summary' => [
                'users' => $users->count(),
                'admins' => $users->where('role', 'admin')->count(),
                'chatbots' => (int) $users->sum('chatbots_count'),
            ],
        ]);
    }

    private function authorizeAdmin(Request $request): User
    {
        $user = $request->attributes->get('auth_user');

        abort_unless($user instanceof User && $user->role === 'admin', 403, 'Admin access required.');

        return $user;
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        ]);
    }
}
