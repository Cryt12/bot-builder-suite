<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
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
