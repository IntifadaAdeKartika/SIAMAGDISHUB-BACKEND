<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pembimbing\KoreksiLogbookRequest;
use App\Http\Resources\LogbookResource;
use App\Models\Logbook;
use App\Models\Notifikasi;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class KoreksiLogbookController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService
    ) {}

    /**
     * Berikan koreksi/catatan serta paraf pada logbook harian peserta.
     */
    public function koreksi(KoreksiLogbookRequest $request, Logbook $logbook): JsonResponse
    {
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing) {
            return response()->json(['success' => false, 'message' => 'Profil pembimbing tidak ditemukan.'], 404);
        }

        if (
            $pembimbing->id_unit_kerja === null
            || ! $logbook->pesertaMagang()
                ->where('id_unit_kerja', $pembimbing->id_unit_kerja)
                ->exists()
        ) {
            return response()->json(['success' => false, 'message' => 'Logbook tidak ditemukan di unit kerja Anda.'], 404);
        }

        $validated = $request->validated();
        $data = [
            'catatan_pembimbing' => $validated['catatan_pembimbing'] ?? null,
            'diverifikasi_oleh' => $pembimbing->id,
            'diverifikasi_pada' => now(),
            'status_verifikasi' => $validated['status_verifikasi'] ?? 'approved',
        ];

        $parafFile = $request->file('paraf_pembimbing') ?? $request->file('paraf_digital');
        if ($parafFile instanceof UploadedFile) {
            $data['paraf_pembimbing'] = $this->fileService->upload($parafFile, 'logbook/paraf');
        } elseif ($request->filled('paraf_digital')) {
            $data['paraf_pembimbing'] = $this->fileService->uploadBase64(
                (string) $request->input('paraf_digital'),
                'logbook/paraf',
            );
        }

        $logbook->update($data);

        ActivityLogger::log('KOREKSI_LOGBOOK', "Pembimbing memberikan koreksi pada logbook ID {$logbook->id}");

        $statusVerifikasi = $data['status_verifikasi'];
        $peserta = $logbook->loadMissing('pesertaMagang')->pesertaMagang;

        if ($peserta && in_array($statusVerifikasi, ['approved', 'rejected'], true)) {
            $isApproved = $statusVerifikasi === 'approved';
            $tanggal = $logbook->tanggal->toDateString();
            Notifikasi::create([
                'id_pengguna' => $peserta->id_pengguna,
                'judul' => $isApproved ? 'Logbook Diverifikasi' : 'Logbook Ditolak',
                'pesan' => "Logbook Anda untuk tanggal {$tanggal} telah ".($isApproved ? 'disetujui' : 'ditolak').' oleh Pembimbing.',
                'tipe' => $isApproved ? 'success' : 'danger',
                'link' => '/peserta/logbook.html',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Koreksi logbook berhasil disimpan.',
            'data' => new LogbookResource($logbook->fresh([
                'pesertaMagang.pengguna',
                'verifikator.pengguna',
            ])),
        ]);
    }
}
