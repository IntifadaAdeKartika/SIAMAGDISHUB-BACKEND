<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pembimbing\KoreksiLogbookRequest;
use App\Http\Requests\Pembimbing\MintaRevisiLogbookRequest;
use App\Http\Requests\Pembimbing\TolakLogbookRequest;
use App\Http\Resources\LogbookResource;
use App\Models\Logbook;
use App\Models\Notifikasi;
use App\Models\Pembimbing;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class KoreksiLogbookController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService
    ) {}

    /**
     * Berikan koreksi pada logbook harian peserta.
     *
     * Mendukung tiga aksi: approve, minta revisi, dan tolak final.
     * Parameter `status_verifikasi=rejected` dari UI lama diperlakukan sebagai
     * permintaan revisi agar logbook tidak terkunci sebagai ditolak.
     */
    public function koreksi(KoreksiLogbookRequest $request, Logbook $logbook): JsonResponse
    {
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing) {
            return response()->json(['success' => false, 'message' => 'Profil pembimbing tidak ditemukan.'], 404);
        }

        if (! $this->isInSupervisorScope($logbook, $pembimbing)) {
            return response()->json(['success' => false, 'message' => 'Logbook tidak ditemukan di unit kerja Anda.'], 404);
        }

        $validated = $request->validated();

        return match ($this->resolveAksi($validated)) {
            'revisi' => $this->prosesPermintaanRevisi($request, $logbook, $pembimbing, $validated),
            'tolak' => $this->prosesTolakFinal($request, $logbook, $pembimbing, $validated),
            default => $this->prosesPersetujuan($request, $logbook, $pembimbing, $validated),
        };
    }

    /**
     * Minta peserta memperbaiki logbook tanpa mengubah status menjadi rejected.
     */
    public function mintaRevisi(MintaRevisiLogbookRequest $request, Logbook $logbook): JsonResponse
    {
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing) {
            return response()->json(['success' => false, 'message' => 'Profil pembimbing tidak ditemukan.'], 404);
        }

        if (! $this->isInSupervisorScope($logbook, $pembimbing)) {
            return response()->json(['success' => false, 'message' => 'Logbook tidak ditemukan di unit kerja Anda.'], 404);
        }

        return $this->prosesPermintaanRevisi($request, $logbook, $pembimbing, $request->validated());
    }

    /**
     * Tolak logbook secara final. Gunakan hanya jika logbook tidak bisa diperbaiki.
     */
    public function tolak(TolakLogbookRequest $request, Logbook $logbook): JsonResponse
    {
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing) {
            return response()->json(['success' => false, 'message' => 'Profil pembimbing tidak ditemukan.'], 404);
        }

        if (! $this->isInSupervisorScope($logbook, $pembimbing)) {
            return response()->json(['success' => false, 'message' => 'Logbook tidak ditemukan di unit kerja Anda.'], 404);
        }

        return $this->prosesTolakFinal($request, $logbook, $pembimbing, $request->validated());
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function prosesPermintaanRevisi(
        Request $request,
        Logbook $logbook,
        Pembimbing $pembimbing,
        array $validated
    ): JsonResponse {
        if ($logbook->status_verifikasi === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Logbook yang sudah disetujui tidak dapat diminta revisi.',
            ], 422);
        }

        if ($logbook->status_verifikasi === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Logbook yang telah ditolak final tidak dapat diminta revisi.',
            ], 422);
        }

        $catatan = trim((string) ($validated['catatan_pembimbing'] ?? ''));

        if ($catatan === '') {
            return $this->catatanWajibResponse();
        }

        $riwayat = $logbook->riwayat_revisi ?? [];
        $riwayat[] = [
            'catatan' => $catatan,
            'pembimbing_id' => $pembimbing->id,
            'pembimbing_nama' => $request->user()->nama_lengkap,
            'diminta_pada' => now()->toISOString(),
            'dikirim_pada' => null,
        ];

        $data = [
            'status_verifikasi' => 'pending',
            'catatan_pembimbing' => $catatan,
            'diverifikasi_oleh' => $pembimbing->id,
            'diverifikasi_pada' => now(),
            'revisi_diminta' => true,
            'revisi_diminta_pada' => now(),
            'revisi_dikirim_pada' => null,
            'riwayat_revisi' => $riwayat,
        ];

        $paraf = $this->resolveParaf($request);
        if ($paraf !== null) {
            $data['paraf_pembimbing'] = $paraf;
        }

        $logbook->update($data);

        ActivityLogger::log('MINTA_REVISI_LOGBOOK', "Pembimbing meminta revisi logbook ID {$logbook->id}");

        $peserta = $logbook->loadMissing('pesertaMagang')->pesertaMagang;
        $tanggal = $logbook->tanggal->toDateString();

        if ($peserta) {
            Notifikasi::create([
                'id_pengguna' => $peserta->id_pengguna,
                'judul' => 'Logbook Perlu Direvisi',
                'pesan' => "Logbook Anda untuk tanggal {$tanggal} (ID #{$logbook->id}) perlu direvisi. Catatan Pembimbing: {$catatan}",
                'tipe' => 'warning',
                'link' => '/peserta/logbook.html',
            ]);
        }

        return $this->logbookResponse($logbook, 'Permintaan revisi logbook berhasil disimpan.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function prosesPersetujuan(
        Request $request,
        Logbook $logbook,
        Pembimbing $pembimbing,
        array $validated
    ): JsonResponse {
        if ($logbook->status_verifikasi === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Logbook yang telah ditolak final tidak dapat disetujui.',
            ], 422);
        }

        if ($logbook->status_verifikasi === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Logbook sudah disetujui.',
            ], 422);
        }

        $data = [
            'status_verifikasi' => 'approved',
            'diverifikasi_oleh' => $pembimbing->id,
            'diverifikasi_pada' => now(),
            'revisi_diminta' => false,
        ];

        if (isset($validated['catatan_pembimbing'])) {
            $data['catatan_pembimbing'] = $validated['catatan_pembimbing'];
        }

        $paraf = $this->resolveParaf($request);
        if ($paraf !== null) {
            $data['paraf_pembimbing'] = $paraf;
        }

        $logbook->update($data);

        ActivityLogger::log('KOREKSI_LOGBOOK', "Pembimbing menyetujui logbook ID {$logbook->id}");

        $peserta = $logbook->loadMissing('pesertaMagang')->pesertaMagang;
        $tanggal = $logbook->tanggal->toDateString();

        if ($peserta) {
            Notifikasi::create([
                'id_pengguna' => $peserta->id_pengguna,
                'judul' => 'Logbook Diverifikasi',
                'pesan' => "Logbook Anda untuk tanggal {$tanggal} telah disetujui oleh Pembimbing.",
                'tipe' => 'success',
                'link' => '/peserta/logbook.html',
            ]);
        }

        return $this->logbookResponse($logbook, 'Logbook berhasil disetujui.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function prosesTolakFinal(
        Request $request,
        Logbook $logbook,
        Pembimbing $pembimbing,
        array $validated
    ): JsonResponse {
        if ($logbook->status_verifikasi === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Logbook yang sudah disetujui tidak dapat ditolak.',
            ], 422);
        }

        if ($logbook->status_verifikasi === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Logbook sudah ditolak final.',
            ], 422);
        }

        $catatan = trim((string) ($validated['catatan_pembimbing'] ?? ''));

        if ($catatan === '') {
            return $this->catatanWajibResponse();
        }

        $data = [
            'status_verifikasi' => 'rejected',
            'catatan_pembimbing' => $catatan,
            'diverifikasi_oleh' => $pembimbing->id,
            'diverifikasi_pada' => now(),
            'revisi_diminta' => false,
        ];

        $paraf = $this->resolveParaf($request);
        if ($paraf !== null) {
            $data['paraf_pembimbing'] = $paraf;
        }

        $logbook->update($data);

        ActivityLogger::log('TOLAK_LOGBOOK', "Pembimbing menolak final logbook ID {$logbook->id}");

        $peserta = $logbook->loadMissing('pesertaMagang')->pesertaMagang;
        $tanggal = $logbook->tanggal->toDateString();

        if ($peserta) {
            Notifikasi::create([
                'id_pengguna' => $peserta->id_pengguna,
                'judul' => 'Logbook Ditolak',
                'pesan' => "Logbook Anda untuk tanggal {$tanggal} telah ditolak oleh Pembimbing.",
                'tipe' => 'danger',
                'link' => '/peserta/logbook.html',
            ]);
        }

        return $this->logbookResponse($logbook, 'Logbook ditolak secara final.');
    }

    private function isInSupervisorScope(Logbook $logbook, Pembimbing $pembimbing): bool
    {
        if ($pembimbing->id_unit_kerja === null) {
            return false;
        }

        return $logbook->pesertaMagang()
            ->where('id_unit_kerja', $pembimbing->id_unit_kerja)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveAksi(array $validated): string
    {
        $aksi = strtolower(trim((string) ($validated['aksi'] ?? '')));

        if ($aksi !== '') {
            return match ($aksi) {
                'revisi', 'minta_revisi' => 'revisi',
                'tolak', 'rejected' => 'tolak',
                default => 'approve',
            };
        }

        $status = strtolower(trim((string) ($validated['status_verifikasi'] ?? '')));

        if ($status === 'approved') {
            return 'approve';
        }

        if (! empty($validated['final'])) {
            return 'tolak';
        }

        if ($status === 'rejected' || $status === 'pending') {
            // UI pembimbing saat ini mengirim "rejected" untuk meminta revisi.
            return 'revisi';
        }

        return 'approve';
    }

    private function catatanWajibResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Catatan pembimbing wajib diisi.',
            'errors' => [
                'catatan_pembimbing' => ['Catatan pembimbing wajib diisi.'],
            ],
        ], 422);
    }

    private function resolveParaf(Request $request): ?string
    {
        $parafFile = $request->file('paraf_pembimbing') ?? $request->file('paraf_digital');

        if ($parafFile instanceof UploadedFile) {
            return $this->fileService->upload($parafFile, 'logbook/paraf');
        }

        if ($request->filled('paraf_digital')) {
            return $this->fileService->uploadBase64(
                (string) $request->input('paraf_digital'),
                'logbook/paraf',
            );
        }

        return null;
    }

    private function logbookResponse(Logbook $logbook, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new LogbookResource($logbook->fresh([
                'pesertaMagang.pengguna',
                'pesertaMagang.unitKerja.pembimbing.pengguna',
                'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
                'verifikator.pengguna',
            ])),
        ]);
    }
}
