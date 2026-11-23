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

class AuthController extends Controller
{
    /**
     * Login pengguna dan terbitkan Sanctum API Token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $pengguna = Pengguna::where('username', $request->username)->first();

        if (! $pengguna || ! Hash::check($request->password, $pengguna->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Username atau password yang Anda masukkan salah.',
            ], 401);
        }

        if ($pengguna->status !== 'aktif') {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda dinonaktifkan. Silakan hubungi administrator.',
            ], 403);
        }

        // Update waktu login terakhir
        $pengguna->update([
            'terakhir_login' => now(),
        ]);

        // Buat Sanctum token dengan ability sesuai role
        $token = $pengguna->createToken('auth-token', [$pengguna->role])->plainTextToken;

        ActivityLogger::log('LOGIN', 'Pengguna login ke dalam sistem', $pengguna->id);

        $pengguna->load([
            'pembimbing.unitKerja',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => new PenggunaResource($pengguna),
            ],
        ]);
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