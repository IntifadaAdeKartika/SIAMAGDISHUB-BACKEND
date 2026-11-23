<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pembimbing\VerifikasiAbsensiRequest;
use App\Http\Resources\AbsensiResource;
use App\Models\Absensi;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class VerifikasiAbsensiController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService
    ) {}

    /**
     * Verifikasi absensi peserta dengan paraf digital.
     * KUNCI KEAMANAN: Jika sudah approved, data terkunci permanen.
     */
    public function verifikasi(VerifikasiAbsensiRequest $request, Absensi $absensi): JsonResponse
    {
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing) {
            return response()->json(['success' => false, 'message' => 'Profil pembimbing tidak ditemukan.'], 404);
        }

        // Validasi kepemilikan: Pembimbing hanya boleh memverifikasi
        // absensi peserta yang berada di unit kerja miliknya.
        if (
            $pembimbing->id_unit_kerja === null
            || ! $absensi->pesertaMagang()
                ->where('id_unit_kerja', $pembimbing->id_unit_kerja)
                ->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Absensi tidak ditemukan di unit kerja Anda.',
            ], 404);
        }

        // ATURAN KEAMANAN: Setelah diverifikasi approved, data absensi TIDAK BOLEH diubah lagi
        if ($absensi->status_verifikasi === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Absensi ini sudah diverifikasi sebelumnya (status: approved) dan terkunci permanen sehingga tidak dapat diubah.',
            ], 422);
        }

        // Upload paraf digital sebagai gambar (bisa Base64 kanvas atau UploadedFile)
        $paraf = $request->paraf_digital;
        if ($paraf instanceof UploadedFile) {
            $parafPath = $this->fileService->upload($paraf, 'paraf/absensi');
        } else {
            $parafPath = $this->fileService->uploadBase64((string) $paraf, 'paraf/absensi');
        }

        $statusBaru = $request->input('status_verifikasi', 'approved');

        $absensi->update([
            'paraf_pembimbing' => $parafPath,
            'diverifikasi_oleh' => $pembimbing->id,
            'diverifikasi_pada' => now(),
            'status_verifikasi' => $statusBaru,
            // Jika status ditolak, simpan alasan penolakan
            'alasan_penolakan' => $statusBaru === 'rejected' ? $request->input('alasan_penolakan') : null,
        ]);

        ActivityLogger::log('VERIFIKASI_ABSENSI', "Pembimbing {$request->user()->nama_lengkap} memverifikasi absensi ID {$absensi->id} ({$statusBaru})");

        return response()->json([
            'success' => true,
            'message' => "Absensi berhasil diverifikasi dengan status '{$statusBaru}'. Data kini terkunci.",
            'data' => new AbsensiResource($absensi->fresh([
                'pesertaMagang.pengguna',
                'pesertaMagang.unitKerja.pembimbing.pengguna',
                'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
                'unitKerja.pembimbing.pengguna',
                'unitKerja.pembimbingByUnit.pengguna',
                'verifikator.pengguna',
            ])),
        ]);
    }
}
