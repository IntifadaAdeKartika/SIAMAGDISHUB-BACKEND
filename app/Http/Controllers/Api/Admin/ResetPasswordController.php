<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetPasswordRequest;
use App\Models\Pengguna;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class ResetPasswordController extends Controller
{
    /**
     * Reset password akun pengguna oleh Admin.
     */
    public function reset(ResetPasswordRequest $request, int $id): JsonResponse
    {
        $pengguna = Pengguna::findOrFail($id);

        $pengguna->update([
            'password' => Hash::make($request->password),
        ]);

        // Revoke semua active tokens pengguna yang direset agar harus login ulang
        $pengguna->tokens()->delete();

        ActivityLogger::log('RESET_PASSWORD', "Admin mereset password pengguna: {$pengguna->username} (ID: {$pengguna->id})");

        return response()->json([
            'success' => true,
            'message' => "Password pengguna {$pengguna->username} berhasil direset.",
        ]);
    }
}
