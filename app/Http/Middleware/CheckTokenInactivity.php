<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class CheckTokenInactivity
{
    /**
     * Memastikan sesi token pengguna tidak aktif (idle) lebih dari durasi yang ditentukan (1 menit).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken() instanceof PersonalAccessToken) {
            $token = $user->currentAccessToken();
            $timeoutSeconds = (int) env('SESSION_INACTIVITY_TIMEOUT', 60); // Default 1 menit (60 detik)

            if ($token->last_used_at) {
                $idleSeconds = now()->diffInSeconds($token->last_used_at);

                if ($idleSeconds > $timeoutSeconds) {
                    // Cabut token karena telah melewati batas waktu inaktivitas
                    $token->delete();

                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Sesi Anda telah berakhir karena tidak ada aktivitas selama 1 menit. Silakan login kembali.',
                        'session_timeout' => true,
                    ], 401);
                }
            }

            // Perbarui waktu aktivitas terakhir
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
