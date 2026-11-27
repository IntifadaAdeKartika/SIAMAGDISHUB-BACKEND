<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pembimbing\VerifikasiAbsensiRequest;
use App\Http\Resources\AbsensiResource;
use App\Models\Absensi;
use App\Models\Notifikasi;
use App\Models\Pembimbing;
use App\Services\AbsensiRevisionService;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class VerifikasiAbsensiController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService,
        protected AbsensiRevisionService $revisionService
    ) {}

    /**
     * Verifikasi absensi peserta.
     * - Persetujuan wajib paraf digital.
     * - Penolakan cukup keterangan dan otomatis mengirim notifikasi ke peserta.
     * - Hanya presensi berstatus pending yang dapat diproses (data terkunci setelahnya).
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

        $statusBaru = (string) $request->input('status_verifikasi', 'approved');

        return DB::transaction(function () use ($request, $absensi, $pembimbing, $statusBaru): JsonResponse {
            $absensi = Absensi::query()->whereKey($absensi->getKey())->lockForUpdate()->firstOrFail();

            if ($absensi->status_verifikasi === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Absensi ini sudah diverifikasi sebelumnya (status: approved) dan terkunci permanen sehingga tidak dapat diubah.',
                ], 422);
            }

            if ($absensi->status_verifikasi === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Presensi ini sudah ditolak dan menunggu perbaikan dari peserta. Verifikasi ulang dapat dilakukan setelah peserta mengirim perbaikan.',
                ], 422);
            }

            if ($absensi->revisi_diminta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Peserta belum mengirim hasil revisi untuk presensi ini. Verifikasi dapat dilakukan setelah revisi dikirim.',
                ], 422);
            }

            if ($statusBaru !== 'rejected') {
                $pasangan = $this->revisionService->pasangan($absensi, true);
                $pasanganLain = $absensi->status === 'masuk' ? $pasangan['check-out'] : $pasangan['check-in'];

                if ($pasanganLain?->revisi_diminta) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Masih ada permintaan revisi aktif pada presensi pasangan hari ini. Selesaikan revisi tersebut sebelum menyetujui presensi ini.',
                    ], 422);
                }
            }

            return $statusBaru === 'rejected'
                ? $this->tolak($request, $absensi, $pembimbing)
                : $this->setujui($request, $absensi, $pembimbing);
        });
    }

    private function setujui(
        VerifikasiAbsensiRequest $request,
        Absensi $absensi,
        Pembimbing $pembimbing
    ): JsonResponse {
        $paraf = $request->paraf_digital;

        if ($paraf === null || $paraf === '') {
            return response()->json([
                'success' => false,
                'message' => 'Paraf pembimbing wajib diunggah untuk menyetujui presensi.',
                'errors' => ['paraf_digital' => ['Paraf pembimbing wajib diunggah untuk menyetujui presensi.']],
            ], 422);
        }

        try {
            $parafPath = $paraf instanceof UploadedFile
                ? $this->fileService->upload($paraf, 'paraf/absensi')
                : $this->fileService->uploadBase64((string) $paraf, 'paraf/absensi');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Paraf pembimbing gagal disimpan: '.$e->getMessage(),
            ], 422);
        }

        $absensi->update([
            'paraf_pembimbing' => $parafPath,
            'diverifikasi_oleh' => $pembimbing->id,
            'diverifikasi_pada' => now(),
            'status_verifikasi' => 'approved',
            'revisi_diminta' => false,
        ]);

        $revisiDisetujui = $this->revisionService->tandaiDisetujui(
            $absensi,
            $pembimbing->id,
            $request->user()->nama_lengkap
        );

        if ($revisiDisetujui) {
            $this->notifyPesertaRevisiDisetujui($absensi);
        }

        ActivityLogger::log(
            'VERIFIKASI_ABSENSI',
            "Pembimbing {$request->user()->nama_lengkap} menyetujui absensi ID {$absensi->id}"
        );

        return $this->absensiResponse(
            $absensi,
            $revisiDisetujui
                ? 'Revisi absensi disetujui. Data kini terkunci.'
                : "Absensi berhasil diverifikasi dengan status 'approved'. Data kini terkunci."
        );
    }

    private function tolak(
        VerifikasiAbsensiRequest $request,
        Absensi $absensi,
        Pembimbing $pembimbing
    ): JsonResponse {
        $alasan = trim((string) $request->input('alasan_penolakan'));

        if ($alasan === '') {
            return response()->json([
                'success' => false,
                'message' => 'Keterangan penolakan wajib diisi.',
                'errors' => ['alasan_penolakan' => ['Keterangan penolakan wajib diisi.']],
            ], 422);
        }

        $riwayat = is_array($absensi->riwayat_penolakan) ? $absensi->riwayat_penolakan : [];
        $riwayat[] = [
            'alasan' => $alasan,
            'jenis' => $absensi->status === 'keluar' ? 'check-out' : 'check-in',
            'pembimbing_id' => $pembimbing->id,
            'pembimbing_nama' => $request->user()->nama_lengkap,
            'pada' => now()->toISOString(),
            'pengajuan_ulang_ke' => (int) $absensi->jumlah_resubmit,
        ];

        // Status dan notifikasi ditulis dalam satu transaksi agar tidak ada
        // penolakan tersimpan tanpa pemberitahuan ke peserta.
        $absensi->update([
            'status_verifikasi' => 'rejected',
            'alasan_penolakan' => $alasan,
            'revisi_diminta' => false,
            'diverifikasi_oleh' => $pembimbing->id,
            'diverifikasi_pada' => now(),
            'riwayat_penolakan' => $riwayat,
        ]);

        $this->revisionService->tandaiDitolak(
            $absensi,
            $pembimbing->id,
            $request->user()->nama_lengkap,
            $alasan
        );

        $this->notifyPesertaPenolakan($absensi, $alasan);

        ActivityLogger::log(
            'TOLAK_PRESENSI',
            "Pembimbing {$request->user()->nama_lengkap} menolak presensi ID {$absensi->id}: {$alasan}"
        );

        return $this->absensiResponse($absensi, 'Penolakan berhasil dikirim kepada peserta.');
    }

    private function notifyPesertaPenolakan(Absensi $absensi, string $alasan): void
    {
        $peserta = $absensi->loadMissing('pesertaMagang')->pesertaMagang;

        if (! $peserta) {
            return;
        }

        $jenis = $absensi->status === 'keluar' ? 'Check-out' : 'Check-in';
        $tanggal = $absensi->tanggal_jam?->format('d/m/Y') ?? '-';

        Notifikasi::create([
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Presensi Ditolak',
            'pesan' => "Presensi {$jenis} Anda tanggal {$tanggal} ditolak oleh pembimbing. Silakan perbaiki presensi sesuai keterangan berikut. Alasan penolakan: {$alasan}",
            'tipe' => 'danger',
            'link' => '/peserta/absensi.html?perbaikan='.$absensi->id,
            'id_absensi' => $absensi->id,
        ]);
    }

    private function notifyPesertaRevisiDisetujui(Absensi $absensi): void
    {
        $peserta = $absensi->loadMissing('pesertaMagang')->pesertaMagang;

        if (! $peserta) {
            return;
        }

        $jenis = $absensi->status === 'keluar' ? 'Check-out' : 'Check-in';
        $tanggal = $absensi->tanggal_jam?->format('d/m/Y') ?? '-';

        Notifikasi::create([
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Revisi Presensi Disetujui',
            'pesan' => "Revisi presensi {$jenis} Anda tanggal {$tanggal} telah disetujui oleh pembimbing.",
            'tipe' => 'success',
            'link' => '/peserta/absensi.html?absensi='.$absensi->id,
            'id_absensi' => $absensi->id,
        ]);
    }

    private function absensiResponse(Absensi $absensi, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
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
