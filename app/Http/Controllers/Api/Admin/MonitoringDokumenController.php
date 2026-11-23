<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PesertaMagangResource;
use App\Models\PesertaMagang;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonitoringDokumenController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService
    ) {}

    /**
     * Tampilkan daftar monitoring dokumen seluruh peserta magang.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PesertaMagang::with(['pengguna', 'unitKerja.pembimbing.pengguna', 'unitKerja.pembimbingByUnit.pengguna']);

        if ($request->filled('id_unit_kerja')) {
            $query->where('id_unit_kerja', $request->id_unit_kerja);
        }

        $list = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => PesertaMagangResource::collection($list)->response()->getData(true),
        ]);
    }

    /**
     * Unduh surat permohonan peserta magang (Read-Only).
     */
    public function downloadSuratPermohonan(int $id_peserta_magang): StreamedResponse
    {
        $peserta = PesertaMagang::with('pengguna')->findOrFail($id_peserta_magang);

        if (! $peserta->file_surat_permohonan) {
            abort(404, 'Surat permohonan belum diunggah oleh peserta.');
        }

        ActivityLogger::log('DOWNLOAD_DOKUMEN', "Admin mengunduh surat permohonan peserta: {$peserta->id}");

        $ext = pathinfo($peserta->file_surat_permohonan, PATHINFO_EXTENSION);
        $cleanName = str_replace(' ', '_', $peserta->pengguna->nama_lengkap ?? 'peserta');

        return $this->fileService->downloadResponse(
            $peserta->file_surat_permohonan,
            "Surat_Permohonan_{$cleanName}.{$ext}"
        );
    }

    /**
     * Unduh surat balasan peserta magang (Read-Only).
     */
    public function downloadSuratBalasan(int $id_peserta_magang): StreamedResponse
    {
        $peserta = PesertaMagang::with('pengguna')->findOrFail($id_peserta_magang);

        if (! $peserta->file_surat_balasan) {
            abort(404, 'Surat balasan belum diunggah oleh pembimbing.');
        }

        ActivityLogger::log('DOWNLOAD_DOKUMEN', "Admin mengunduh surat balasan peserta: {$peserta->id}");

        $ext = pathinfo($peserta->file_surat_balasan, PATHINFO_EXTENSION);
        $cleanName = str_replace(' ', '_', $peserta->pengguna->nama_lengkap ?? 'peserta');

        return $this->fileService->downloadResponse(
            $peserta->file_surat_balasan,
            "Surat_Balasan_{$cleanName}.{$ext}"
        );
    }
}
