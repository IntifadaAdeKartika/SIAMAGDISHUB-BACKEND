<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Logbook;
use App\Models\Pengguna;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogbookLampiranController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService
    ) {}

    /**
     * Sajikan lampiran dokumentasi logbook tanpa mengekspos Base64 sebagai URL.
     */
    public function show(Request $request, Logbook $logbook): Response
    {
        $user = $request->user();

        if (! $user instanceof Pengguna || ! $this->bolehAkses($user, $logbook)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Anda tidak berhak membuka lampiran logbook ini.',
            ], 403);
        }

        $path = trim((string) $logbook->foto_kegiatan);

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'Logbook ini tidak memiliki lampiran dokumentasi.',
            ], 404);
        }

        // Lampiran lama dapat berupa URL lengkap; arahkan tanpa mengubah datanya.
        if (preg_match('#^https?://#i', $path)) {
            return redirect()->away($path);
        }

        $download = $request->boolean('download');
        $filename = 'Lampiran_Logbook_'.$logbook->id;

        // Hanya tipe aman yang boleh ditampilkan inline; tipe lain dipaksa unduh.
        if ($download || ! $this->bisaDitampilkanInline($path)) {
            return $this->fileService->downloadResponse($path, $filename);
        }

        return $this->fileService->inlineResponse($path, $filename);
    }

    private function bisaDitampilkanInline(string $path): bool
    {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf'];

        if (preg_match('/^data:([^;,]+)/i', $path, $match)) {
            return in_array(strtolower($match[1]), $allowed, true);
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf'], true);
    }

    private function bolehAkses(Pengguna $user, Logbook $logbook): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $peserta = $logbook->pesertaMagang;

        if (! $peserta) {
            return false;
        }

        if ($user->isPesertaMagang()) {
            return (int) $peserta->id_pengguna === (int) $user->id;
        }

        if ($user->isPembimbing()) {
            $pembimbing = $user->pembimbing;

            return $pembimbing !== null
                && $pembimbing->id_unit_kerja !== null
                && (int) $peserta->id_unit_kerja === (int) $pembimbing->id_unit_kerja;
        }

        return false;
    }
}
