<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AbsensiMediaController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService
    ) {}

    public function selfie(Absensi $absensi): Response
    {
        return $this->sajikan($absensi->foto_selfie, 'Selfie_Absensi_'.$absensi->id);
    }

    public function paraf(Absensi $absensi): Response
    {
        return $this->sajikan($absensi->paraf_pembimbing, 'Paraf_Absensi_'.$absensi->id);
    }

    public function revisi(Request $request, Absensi $absensi, int $index, string $posisi, string $field): Response
    {
        if (! in_array($posisi, ['sebelum', 'setelah'], true)
            || ! in_array($field, ['foto_selfie', 'paraf_pembimbing'], true)) {
            return $this->tidakDitemukan();
        }

        $riwayat = $absensi->riwayat_revisi ?? [];
        $entri = $riwayat[$index] ?? null;

        if (! is_array($entri)) {
            return $this->tidakDitemukan();
        }

        $path = $entri['data_'.$posisi][$field] ?? null;

        if (! is_string($path) || trim($path) === '') {
            return $this->tidakDitemukan();
        }

        return $this->sajikan($path, 'Revisi_'.$posisi.'_'.$field.'_'.$absensi->id);
    }

    private function sajikan(?string $path, string $filename): Response
    {
        if (! is_string($path) || trim($path) === '') {
            return $this->tidakDitemukan();
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return redirect()->away($path);
        }

        if (! str_starts_with($path, 'data:') && ! $this->fileServiceTersedia($path)) {
            return $this->tidakDitemukan();
        }

        return $this->fileService->inlineResponse($path, $filename);
    }

    private function fileServiceTersedia(string $path): bool
    {
        try {
            return Storage::disk(config('siamag.storage_disk', 'public'))->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    private function tidakDitemukan(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Media absensi tidak ditemukan.',
        ], 404);
    }
}
