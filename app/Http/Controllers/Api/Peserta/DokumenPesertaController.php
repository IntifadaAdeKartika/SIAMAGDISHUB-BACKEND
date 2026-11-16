<?php

namespace App\Http\Controllers\Api\Peserta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Peserta\UploadSuratPermohonanRequest;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DokumenPesertaController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService
    ) {}

    /**
     * Upload atau re-upload surat permohonan magang dari pihak sekolah/kampus.
     */
    public function uploadSuratPermohonan(UploadSuratPermohonanRequest $request): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;

        if (! $peserta) {
            return response()->json(['success' => false, 'message' => 'Profil peserta tidak ditemukan.'], 404);
        }

        // Hapus file lama jika re-upload
        if ($peserta->file_surat_permohonan) {
            $this->fileService->delete($peserta->file_surat_permohonan);
        }

        $path = $this->fileService->upload($request->file('file_surat_permohonan'), 'dokumen/surat_permohonan');

        $peserta->update([
            'file_surat_permohonan' => $path,
        ]);

        ActivityLogger::log('UPLOAD_SURAT_PERMOHONAN', "Peserta {$request->user()->nama_lengkap} mengunggah surat permohonan");

        return response()->json([
            'success' => true,
            'message' => 'Surat permohonan magang berhasil diunggah.',
            'data' => [
                'file_surat_permohonan_url' => $this->fileService->url($path),
                'status_dokumen' => $peserta->status_dokumen,
            ],
        ]);
    }

    /**
     * Melihat dan mengunduh surat permohonan miliknya sendiri.
     */
    public function downloadSuratPermohonan(Request $request): StreamedResponse
    {
        $peserta = $request->user()->pesertaMagang;

        if (! $peserta || ! $peserta->file_surat_permohonan) {
            abort(404, 'Surat permohonan belum diunggah.');
        }

        $ext = pathinfo($peserta->file_surat_permohonan, PATHINFO_EXTENSION);
        $cleanName = str_replace(' ', '_', $request->user()->nama_lengkap);

        return $this->fileService->downloadResponse(
            $peserta->file_surat_permohonan,
            "Surat_Permohonan_{$cleanName}.{$ext}"
        );
    }

    /**
     * Melihat dan mengunduh surat balasan yang diunggah oleh pembimbing.
     */
    public function downloadSuratBalasan(Request $request): StreamedResponse
    {
        $peserta = $request->user()->pesertaMagang;

        if (! $peserta || ! $peserta->file_surat_balasan) {
            abort(404, 'Surat balasan dari instansi belum tersedia/belum diunggah oleh pembimbing.');
        }

        $ext = pathinfo($peserta->file_surat_balasan, PATHINFO_EXTENSION);
        $cleanName = str_replace(' ', '_', $request->user()->nama_lengkap);

        return $this->fileService->downloadResponse(
            $peserta->file_surat_balasan,
            "Surat_Balasan_{$cleanName}.{$ext}"
        );
    }
}
