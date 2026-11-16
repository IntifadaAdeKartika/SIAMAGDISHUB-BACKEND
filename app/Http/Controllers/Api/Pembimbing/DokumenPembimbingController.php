<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pembimbing\UploadSuratBalasanRequest;
use App\Models\PesertaMagang;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DokumenPembimbingController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService
    ) {}

    /**
     * Melihat dan mengunduh surat permohonan peserta magang (Read-Only / Arsip).
     */
    public function downloadSuratPermohonan(Request $request, int $id_peserta_magang): StreamedResponse
    {
        $peserta = $this->findPesertaBimbingan($request, $id_peserta_magang);

        if (! $peserta->file_surat_permohonan) {
            abort(404, 'Surat permohonan belum diunggah oleh peserta.');
        }

        $ext = pathinfo($peserta->file_surat_permohonan, PATHINFO_EXTENSION);
        $cleanName = str_replace(' ', '_', $peserta->pengguna->nama_lengkap ?? 'peserta');

        return $this->fileService->downloadResponse(
            $peserta->file_surat_permohonan,
            "Surat_Permohonan_{$cleanName}.{$ext}"
        );
    }

    /**
     * Mengunggah (atau mengunggah ulang) surat balasan dari Dishub ke sistem milik peserta.
     * OTOMATIS mengubah status_dokumen menjadi 'lengkap'.
     */
    public function uploadSuratBalasan(UploadSuratBalasanRequest $request, int $id_peserta_magang): JsonResponse
    {
        $peserta = $this->findPesertaBimbingan($request, $id_peserta_magang);

        // Hapus file lama jika re-upload
        if ($peserta->file_surat_balasan) {
            $this->fileService->delete($peserta->file_surat_balasan);
        }

        $path = $this->fileService->upload($request->file('file_surat_balasan'), 'dokumen/surat_balasan');

        // ATURAN OTORISASI: Otomatis set status_dokumen = 'lengkap'
        $peserta->update([
            'file_surat_balasan' => $path,
            'status_dokumen' => 'lengkap',
        ]);

        ActivityLogger::log('UPLOAD_SURAT_BALASAN', "Pembimbing mengunggah surat balasan untuk peserta: {$peserta->id}");

        return response()->json([
            'success' => true,
            'message' => 'Surat balasan berhasil diunggah. Status dokumen peserta otomatis diperbarui menjadi "lengkap".',
            'data' => [
                'id_peserta_magang' => $peserta->id,
                'file_surat_balasan_url' => $this->fileService->url($path),
                'status_dokumen' => $peserta->status_dokumen,
            ],
        ]);
    }

    /**
     * Melihat dan mengunduh surat balasan milik peserta magang.
     */
    public function downloadSuratBalasan(Request $request, int $id_peserta_magang): StreamedResponse
    {
        $peserta = $this->findPesertaBimbingan($request, $id_peserta_magang);

        if (! $peserta->file_surat_balasan) {
            abort(404, 'Surat balasan belum diunggah untuk peserta ini.');
        }

        $ext = pathinfo($peserta->file_surat_balasan, PATHINFO_EXTENSION);
        $cleanName = str_replace(' ', '_', $peserta->pengguna->nama_lengkap ?? 'peserta');

        return $this->fileService->downloadResponse(
            $peserta->file_surat_balasan,
            "Surat_Balasan_{$cleanName}.{$ext}"
        );
    }

    private function findPesertaBimbingan(Request $request, int $idPesertaMagang): PesertaMagang
    {
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing || ! $pembimbing->id_unit_kerja) {
            abort(404, 'Profil atau unit kerja pembimbing tidak ditemukan.');
        }

        return PesertaMagang::with('pengguna')
            ->where('id_unit_kerja', $pembimbing->id_unit_kerja)
            ->findOrFail($idPesertaMagang);
    }
}
