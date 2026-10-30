<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Support\AuditLogger;

class AuthController extends Controller
{
    public function login(Request $request, RateLimiter $limiter): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $username = Str::lower(trim($data['username']));
        $key = Str::transliterate($username.'|'.$request->ip());
        if ($limiter->tooManyAttempts('sikomit-login:'.$key, 5)) {
            return response()->json(['message' => 'Terlalu banyak percobaan login. Coba lagi beberapa saat.'], 429);
        }

        $user = User::query()->where('username', $username)->first();
        if (!$user || !$user->is_active || !Hash::check($data['password'], $user->password)) {
            $limiter->hit('sikomit-login:'.$key, 600);
            app(AuditLogger::class)->record($request, 'LOGIN_FAILED', 'User', $username, null, null);
            return response()->json(['message' => 'Username atau password tidak sesuai.'], 401);
        }

        $roles = $user->roles()->where('roles.is_active', true)->pluck('roles.code')->all();
        if ($roles === []) {
            return response()->json(['message' => 'Akun belum memiliki peran aktif. Hubungi administrator.'], 403);
        }

        $limiter->clear('sikomit-login:'.$key);
        $user->forceFill(['last_login_at' => now()])->save();
        app(AuditLogger::class)->record($request, 'LOGIN_SUCCEEDED', 'User', $user->id, null, null, $user->id);
        $token = $user->createToken('sikomit-android', ['administration'])->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'user' => [
                'id' => (string) $user->id,
                'nama' => $user->name,
                'username' => $user->username,
                'role' => $roles[0],
                'roles' => $roles,
                'mitraId' => $user->partner_id ? (string) $user->partner_id : '',
                'mustChangePassword' => $user->must_change_password,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        return response()->json([
            'login' => true,
            'user' => [
                'id' => (string) $user->id,
                'nama' => $user->name,
                'username' => $user->username,
                'role' => $user->roles->first()?->code,
                'roles' => $user->roles->pluck('code')->values(),
                'mitraId' => $user->partner_id ? (string) $user->partner_id : '',
                'mustChangePassword' => $user->must_change_password,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        app(AuditLogger::class)->record($request, 'LOGOUT', 'User', $request->user()->id, null, null, $request->user()->id);
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:12', 'confirmed', 'max:200'],
        ]);
        $user = $request->user();

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Password saat ini tidak sesuai.'], 422);
        }

        $currentTokenId = $user->currentAccessToken()?->getKey();
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        $user->tokens()->when($currentTokenId, fn ($query) => $query->where('id', '<>', $currentTokenId))->delete();
        app(AuditLogger::class)->record($request, 'PASSWORD_CHANGED', 'User', $user->id, null, ['must_change_password' => false], $user->id);

        return response()->json(['ok' => true]);
    }
}
