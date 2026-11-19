<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\PenggunaResource;
use App\Models\Pengguna;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    /**
     * Login pengguna dan terbitkan Sanctum API Token.
     */

public function login(LoginRequest $request): JsonResponse
{
    $stage = 'controller_entered';

    try {
        $stage = 'find_user';
        $pengguna = Pengguna::where('username', $request->username)->first();

        if (! $pengguna || ! Hash::check($request->password, $pengguna->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Username atau password yang Anda masukkan salah.',
            ], 401);
        }

        $stage = 'check_user_status';

        if ($pengguna->status !== 'aktif') {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda dinonaktifkan. Silakan hubungi administrator.',
            ], 403);
        }

        $stage = 'update_last_login';

        $pengguna->update([
            'terakhir_login' => now(),
        ]);

        $stage = 'create_sanctum_token';

        $token = $pengguna->createToken('auth-token', [$pengguna->role])->plainTextToken;

        $stage = 'write_activity_log';

        ActivityLogger::log('LOGIN', 'Pengguna login ke dalam sistem', $pengguna->id);

        $stage = 'load_user_relations';

        $pengguna->load([
            'pembimbing.unitKerja',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
        ]);

        $stage = 'resolve_user_resource';

        $userResource = new PenggunaResource($pengguna);
        $userResource->resolve($request);

        $stage = 'build_response';

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $userResource,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('[SIAMAG_LOGIN_DIAGNOSTIC] '.json_encode([
            'stage' => $stage,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        throw $e;
    }
}


    /**
     * Dapatkan data profil pengguna yang sedang login.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'pembimbing.unitKerja',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
        ]);

        return response()->json([
            'success' => true,
            'data' => new PenggunaResource($user),
        ]);
    }

    /**
     * Logout pengguna dan cabut token saat ini.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        ActivityLogger::log('LOGOUT', 'Pengguna logout dari sistem', $user->id);

        // Hapus token yang sedang digunakan untuk request ini
        $user->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil. Token telah dicabut.',
        ]);
    }
}
