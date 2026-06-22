<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
        ]);

        $user = User::create([
            'name' => $data['name'] ?: Str::before($data['email'], '@'),
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'user',
        ]);

        return $this->authResponse($user);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->authResponse($user);
    }

    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'redirect_uri' => ['required', 'url'],
        ]);

        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        if (! $clientId || ! $clientSecret) {
            return $this->json(['message' => 'Google sign-in is not configured.'], 500);
        }

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $data['code'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $data['redirect_uri'],
        ]);

        if ($tokenResponse->failed()) {
            throw ValidationException::withMessages([
                'google' => ['Google authorization failed. Please try again.'],
            ]);
        }

        $accessToken = $tokenResponse->json('access_token');

        if (! $accessToken) {
            throw ValidationException::withMessages([
                'google' => ['Google did not return an access token.'],
            ]);
        }

        $profileResponse = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if ($profileResponse->failed()) {
            throw ValidationException::withMessages([
                'google' => ['Could not read your Google profile.'],
            ]);
        }

        $profile = $profileResponse->json();
        $email = $profile['email'] ?? null;
        $googleId = $profile['sub'] ?? null;

        if (! $email || ! $googleId) {
            throw ValidationException::withMessages([
                'google' => ['Your Google account did not provide an email address.'],
            ]);
        }

        $user = User::where('google_id', $googleId)->orWhere('email', $email)->first();
        $isVerified = (bool) ($profile['email_verified'] ?? false);

        if ($user) {
            $user->fill([
                'google_id' => $user->google_id ?: $googleId,
                'name' => $user->name ?: ($profile['name'] ?? Str::before($email, '@')),
            ]);

            if ($isVerified && ! $user->email_verified_at) {
                $user->email_verified_at = now();
            }

            $user->save();
        } else {
            $user = User::create([
                'name' => $profile['name'] ?? Str::before($email, '@'),
                'email' => $email,
                'google_id' => $googleId,
                'email_verified_at' => $isVerified ? now() : null,
                'password' => Str::random(40),
                'role' => 'user',
            ]);
        }

        return $this->authResponse($user);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->json([
            'user' => $this->serializeUser($request->attributes->get('auth_user')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('auth_token');

        if ($token instanceof ApiToken) {
            $token->delete();
        }

        return $this->json(['ok' => true]);
    }

    private function authResponse(User $user): JsonResponse
    {
        $plainToken = Str::random(80);

        ApiToken::create([
            'user_id' => $user->id,
            'name' => 'web',
            'token_hash' => hash('sha256', $plainToken),
        ]);

        return $this->json([
            'token' => $plainToken,
            'user' => $this->serializeUser($user),
        ]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status)
            ->withHeaders($this->corsHeaders());
    }

    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        ];
    }
}
