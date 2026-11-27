<?php

namespace App\Http\Controllers\Api\Peserta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Peserta\CheckInRequest;
use App\Http\Requests\Peserta\CheckOutRequest;
use App\Http\Resources\AbsensiResource;
use App\Models\Absensi;
use App\Models\Notifikasi;
use App\Models\PesertaMagang;
use App\Services\AbsensiRevisionService;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use App\Services\GeofencingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AbsensiController extends Controller
{
    public function __construct(
        protected GeofencingService $geofencing,
        protected FileUploadService $fileService,
        protected AbsensiRevisionService $revisionService
    ) {}

    /**
     * Catat absensi masuk (Check-In) peserta magang.
     */
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $peserta = $user->pesertaMagang;

            if (! $peserta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil peserta magang tidak ditemukan.',
                ], 404);
            }

            // 1. Server-Side Geofencing Validation (Radius toleransi max 70 meter)
            $geoCheck = $this->geofencing->checkWithinRadius(
                (float) $request->latitude,
                (float) $request->longitude
            );

            if (! $geoCheck['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $geoCheck['message'],
                    'data' => [
                        'closest_office' => $geoCheck['closest_office'],
                        'details' => $geoCheck['details'],
                        'max_distance_meters' => $geoCheck['max_distance'],
                    ],
                ], 422);
            }

            return DB::transaction(function () use ($request, $user, $peserta, $geoCheck): JsonResponse {
                PesertaMagang::query()
                    ->whereKey($peserta->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $today = Carbon::today();

                if ($request->filled('id_absensi')) {
                    $existingCheckIn = Absensi::where('id_peserta_magang', $peserta->id)
                        ->whereKey($request->input('id_absensi'))
                        ->where('status', 'masuk')
                        ->lockForUpdate()
                        ->first();

                    if (! $existingCheckIn) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Data check-in tidak ditemukan atau bukan milik Anda.',
                        ], 404);
                    }
                } else {
                    $revisiAktif = Absensi::where('id_peserta_magang', $peserta->id)
                        ->where('status', 'masuk')
                        ->where('status_verifikasi', 'pending')
                        ->where('revisi_diminta', true)
                        ->orderByDesc('tanggal_jam')
                        ->lockForUpdate()
                        ->get();

                    if ($revisiAktif->count() > 1) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Terdapat lebih dari satu permintaan revisi check-in. Sertakan id_absensi yang ingin diperbaiki.',
                        ], 422);
                    }

                    $existingCheckIn = $revisiAktif->first()
                        ?? Absensi::where('id_peserta_magang', $peserta->id)
                            ->where('status', 'masuk')
                            ->whereDate('tanggal_jam', $today)
                            ->lockForUpdate()
                            ->first();
                }

                if ($existingCheckIn) {
                    if ($existingCheckIn->status_verifikasi === 'rejected' && $existingCheckIn->jumlah_resubmit < 2) {
                        $existingCheckIn->increment('jumlah_resubmit');
                        $existingCheckIn->update([
                            'foto_selfie' => $this->handleSelfieUpload($request->foto_selfie),
                            'lokasi' => $request->lokasi ?: $geoCheck['matched_office']['name'],
                            'latitude' => $request->latitude,
                            'longitude' => $request->longitude,
                            'keterangan' => $request->keterangan,
                            'status_verifikasi' => 'pending',
                            'alasan_penolakan' => null,
                        ]);

                        ActivityLogger::log('RECHECK_IN', "Peserta {$user->nama_lengkap} melakukan check-in ulang (resubmit #{$existingCheckIn->jumlah_resubmit}).");
                        $this->notifyPembimbingCheckIn($peserta, $user->nama_lengkap);

                        return response()->json([
                            'success' => true,
                            'message' => 'Check-in ulang berhasil dicatat.',
                            'data' => new AbsensiResource($existingCheckIn->fresh([
                                'unitKerja.pembimbing.pengguna',
                                'unitKerja.pembimbingByUnit.pengguna',
                                'pesertaMagang.pengguna',
                                'pesertaMagang.unitKerja.pembimbing.pengguna',
                                'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
                            ])),
                        ]);
                    }

                    if ($existingCheckIn->status_verifikasi === 'rejected') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Batas maksimal perbaikan presensi (2 kali) telah tercapai. Silakan hubungi pembimbing.',
                        ], 422);
                    }

                    if ($existingCheckIn->status_verifikasi === 'pending' && $existingCheckIn->revisi_diminta) {
                        $fotoPath = $this->handleSelfieUpload($request->foto_selfie);
                        $lokasiFinal = $request->lokasi ?: $geoCheck['matched_office']['name'];

                        $existingCheckIn->update([
                            'foto_selfie' => $fotoPath,
                            'lokasi' => $lokasiFinal,
                            'latitude' => $request->latitude,
                            'longitude' => $request->longitude,
                            'keterangan' => $request->keterangan,
                        ]);

                        $this->revisionService->catatPerbaikanPeserta($existingCheckIn, [
                            'tanggal_jam' => $existingCheckIn->tanggal_jam?->toISOString(),
                            'foto_selfie' => $fotoPath,
                            'lokasi' => $lokasiFinal,
                            'latitude' => (string) $request->latitude,
                            'longitude' => (string) $request->longitude,
                            'keterangan' => $request->keterangan,
                            'status' => 'masuk',
                            'status_verifikasi' => 'pending',
                        ]);

                        ActivityLogger::log(
                            'REVISI_CHECK_IN',
                            "Peserta {$user->nama_lengkap} memperbaiki check-in ID {$existingCheckIn->id} sesuai permintaan revisi."
                        );

                        return response()->json([
                            'success' => true,
                            'message' => 'Perbaikan check-in tersimpan. Silakan kirim hasil revisi untuk diverifikasi pembimbing.',
                            'data' => new AbsensiResource($existingCheckIn->fresh([
                                'unitKerja.pembimbing.pengguna',
                                'unitKerja.pembimbingByUnit.pengguna',
                                'pesertaMagang.pengguna',
                                'pesertaMagang.unitKerja.pembimbing.pengguna',
                                'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
                            ])),
                        ]);
                    }

                    return response()->json([
                        'success' => false,
                        'message' => 'Anda sudah melakukan check-in untuk hari ini.',
                        'data' => new AbsensiResource($existingCheckIn),
                    ], 422);
                }

                $fotoPath = $this->handleSelfieUpload($request->foto_selfie);
                $lokasiFinal = $request->lokasi ?: $geoCheck['matched_office']['name'];

                // Pastikan kolom foto_selfie & paraf_pembimbing bertipe TEXT di database
                try {
                    DB::statement('ALTER TABLE absensi ALTER COLUMN foto_selfie TYPE TEXT');
                    DB::statement('ALTER TABLE absensi ALTER COLUMN paraf_pembimbing TYPE TEXT');
                } catch (\Throwable) {
                    // Abaikan jika sudah bertipe TEXT
                }

                $absensi = Absensi::create([
                    'id_peserta_magang' => $peserta->id,
                    'id_unit_kerja' => $peserta->id_unit_kerja,
                    'tanggal_jam' => now(),
                    'foto_selfie' => $fotoPath,
                    'lokasi' => $lokasiFinal,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'status' => 'masuk',
                    'keterangan' => $request->keterangan,
                    'status_verifikasi' => 'pending',
                ]);

                ActivityLogger::log('CHECK_IN', "Peserta {$user->nama_lengkap} melakukan check-in di {$geoCheck['matched_office']['name']} (jarak {$geoCheck['matched_office']['distance']}m)");
                $this->notifyPembimbingCheckIn($peserta, $user->nama_lengkap);

                return response()->json([
                    'success' => true,
                    'message' => 'Presensi masuk (Check-In) berhasil dicatat.',
                    'data' => new AbsensiResource($absensi->load([
                        'unitKerja.pembimbing.pengguna',
                        'unitKerja.pembimbingByUnit.pengguna',
                        'pesertaMagang.pengguna',
                        'pesertaMagang.unitKerja.pembimbing.pengguna',
                        'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
                    ])),
                ], 201);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal check-in: '.$e->getMessage(),
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Catat absensi pulang (Check-Out) peserta magang.
     */
    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        $user = $request->user();
        $peserta = $user->pesertaMagang;

        if (! $peserta) {
            return response()->json([
                'success' => false,
                'message' => 'Profil peserta magang tidak ditemukan.',
            ], 404);
        }

        // 1. Geofencing check
        $geoCheck = $this->geofencing->checkWithinRadius(
            (float) $request->latitude,
            (float) $request->longitude
        );

        if (! $geoCheck['valid']) {
            return response()->json([
                'success' => false,
                'message' => $geoCheck['message'],
                'data' => [
                    'closest_office' => $geoCheck['closest_office'],
                    'details' => $geoCheck['details'],
                    'max_distance_meters' => $geoCheck['max_distance'],
                ],
            ], 422);
        }

        return DB::transaction(function () use ($request, $user, $peserta, $geoCheck): JsonResponse {
            PesertaMagang::query()->whereKey($peserta->id)->lockForUpdate()->firstOrFail();

            $today = Carbon::today();

            if ($request->filled('id_absensi')) {
                $existingCheckOut = Absensi::where('id_peserta_magang', $peserta->id)
                    ->whereKey($request->input('id_absensi'))
                    ->where('status', 'keluar')
                    ->lockForUpdate()
                    ->first();

                if (! $existingCheckOut) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Data check-out tidak ditemukan atau bukan milik Anda.',
                    ], 404);
                }
            } else {
                $revisiAktif = Absensi::where('id_peserta_magang', $peserta->id)
                    ->where('status', 'keluar')
                    ->where('status_verifikasi', 'pending')
                    ->where('revisi_diminta', true)
                    ->orderByDesc('tanggal_jam')
                    ->lockForUpdate()
                    ->get();

                if ($revisiAktif->count() > 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Terdapat lebih dari satu permintaan revisi check-out. Sertakan id_absensi yang ingin diperbaiki.',
                    ], 422);
                }

                $existingCheckOut = $revisiAktif->first()
                    ?? Absensi::where('id_peserta_magang', $peserta->id)
                        ->where('status', 'keluar')
                        ->whereDate('tanggal_jam', $today)
                        ->lockForUpdate()
                        ->first();
            }

            if ($existingCheckOut) {
                if ($existingCheckOut->status_verifikasi === 'rejected') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Presensi check-out ini sedang menunggu perbaikan. Gunakan menu perbaikan presensi.',
                    ], 422);
                }

                if ($existingCheckOut->status_verifikasi === 'pending' && $existingCheckOut->revisi_diminta) {
                    $fotoPath = $this->handleSelfieUpload($request->foto_selfie);
                    $lokasiFinal = $request->lokasi ?: $geoCheck['matched_office']['name'];

                    $existingCheckOut->update([
                        'foto_selfie' => $fotoPath,
                        'lokasi' => $lokasiFinal,
                        'latitude' => $request->latitude,
                        'longitude' => $request->longitude,
                        'keterangan' => $request->keterangan,
                    ]);

                    $this->revisionService->catatPerbaikanPeserta($existingCheckOut, [
                        'tanggal_jam' => $existingCheckOut->tanggal_jam?->toISOString(),
                        'foto_selfie' => $fotoPath,
                        'lokasi' => $lokasiFinal,
                        'latitude' => (string) $request->latitude,
                        'longitude' => (string) $request->longitude,
                        'keterangan' => $request->keterangan,
                        'status' => 'keluar',
                        'status_verifikasi' => 'pending',
                    ]);

                    ActivityLogger::log(
                        'REVISI_CHECK_OUT',
                        "Peserta {$user->nama_lengkap} memperbaiki check-out ID {$existingCheckOut->id} sesuai permintaan revisi."
                    );

                    return response()->json([
                        'success' => true,
                        'message' => 'Perbaikan check-out tersimpan. Silakan kirim hasil revisi untuk diverifikasi pembimbing.',
                        'data' => new AbsensiResource($existingCheckOut->fresh([
                            'unitKerja.pembimbing.pengguna',
                            'unitKerja.pembimbingByUnit.pengguna',
                            'pesertaMagang.pengguna',
                            'pesertaMagang.unitKerja.pembimbing.pengguna',
                            'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
                        ])),
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah melakukan check-out untuk hari ini.',
                    'data' => new AbsensiResource($existingCheckOut),
                ], 422);
            }

            if (! $request->filled('id_absensi')) {
                $hasCheckIn = Absensi::where('id_peserta_magang', $peserta->id)
                    ->where('status', 'masuk')
                    ->whereDate('tanggal_jam', $today)
                    ->exists();

                if (! $hasCheckIn) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum melakukan check-in hari ini. Tidak dapat melakukan check-out.',
                    ], 422);
                }
            }

            $fotoPath = $this->handleSelfieUpload($request->foto_selfie);
            $lokasiFinal = $request->lokasi ?: $geoCheck['matched_office']['name'];

            try {
                DB::statement('ALTER TABLE absensi ALTER COLUMN foto_selfie TYPE TEXT');
                DB::statement('ALTER TABLE absensi ALTER COLUMN paraf_pembimbing TYPE TEXT');
            } catch (\Throwable) {
            }

            $absensi = Absensi::create([
                'id_peserta_magang' => $peserta->id,
                'id_unit_kerja' => $peserta->id_unit_kerja,
                'tanggal_jam' => now(),
                'foto_selfie' => $fotoPath,
                'lokasi' => $lokasiFinal,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'status' => 'keluar',
                'keterangan' => $request->keterangan,
                'status_verifikasi' => 'pending',
            ]);

            ActivityLogger::log('CHECK_OUT', "Peserta {$user->nama_lengkap} melakukan check-out di {$geoCheck['matched_office']['name']} (jarak {$geoCheck['matched_office']['distance']}m)");

            return response()->json([
                'success' => true,
                'message' => 'Presensi keluar (Check-Out) berhasil dicatat.',
                'data' => new AbsensiResource($absensi->load([
                    'unitKerja.pembimbing.pengguna',
                    'unitKerja.pembimbingByUnit.pengguna',
                    'pesertaMagang.pengguna',
                    'pesertaMagang.unitKerja.pembimbing.pengguna',
                    'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
                ])),
            ], 201);
        });
    }

    /**
     * Perbaiki presensi (Check-In/Check-Out) yang ditolak pembimbing.
     *
     * Memperbarui baris presensi yang sama agar tidak membuat data duplikat
     * dan tetap terhubung dengan riwayat penolakan sebelumnya.
     */
    public function perbaikan(CheckInRequest $request, Absensi $absensi): JsonResponse
    {
        $user = $request->user();
        $peserta = $user->pesertaMagang;

        if (! $peserta) {
            return response()->json([
                'success' => false,
                'message' => 'Profil peserta magang tidak ditemukan.',
            ], 404);
        }

        if ((int) $absensi->id_peserta_magang !== (int) $peserta->id) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Presensi ini bukan milik Anda.',
            ], 403);
        }

        if ($absensi->status_verifikasi === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Presensi yang sudah disetujui tidak dapat diperbaiki.',
            ], 422);
        }

        if ($absensi->status_verifikasi !== 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya presensi yang ditolak yang dapat diperbaiki.',
            ], 422);
        }

        if ((int) $absensi->jumlah_resubmit >= 2) {
            return response()->json([
                'success' => false,
                'message' => 'Batas maksimal perbaikan presensi (2 kali) telah tercapai. Silakan hubungi pembimbing.',
            ], 422);
        }

        // Aturan GPS/geofencing tetap sama dengan presensi biasa.
        $geoCheck = $this->geofencing->checkWithinRadius(
            (float) $request->latitude,
            (float) $request->longitude
        );

        if (! $geoCheck['valid']) {
            return response()->json([
                'success' => false,
                'message' => $geoCheck['message'],
                'data' => [
                    'closest_office' => $geoCheck['closest_office'],
                    'details' => $geoCheck['details'],
                    'max_distance_meters' => $geoCheck['max_distance'],
                ],
            ], 422);
        }

        try {
            $diperbaiki = DB::transaction(function () use ($request, $absensi, $peserta, $user, $geoCheck): ?Absensi {
                $absensi = Absensi::query()->whereKey($absensi->getKey())->lockForUpdate()->firstOrFail();

                // Cek ulang di dalam lock agar perbaikan ganda tidak lolos.
                if (
                    (int) $absensi->id_peserta_magang !== (int) $peserta->id
                    || $absensi->status_verifikasi !== 'rejected'
                    || (int) $absensi->jumlah_resubmit >= 2
                ) {
                    return null;
                }

                $riwayat = is_array($absensi->riwayat_penolakan) ? $absensi->riwayat_penolakan : [];

                // Lampiran lama (sebelum kolom riwayat ada) tetap dipertahankan.
                if ($riwayat === [] && $absensi->alasan_penolakan) {
                    $riwayat[] = [
                        'alasan' => $absensi->alasan_penolakan,
                        'jenis' => $absensi->status === 'keluar' ? 'check-out' : 'check-in',
                        'pembimbing_id' => $absensi->diverifikasi_oleh,
                        'pembimbing_nama' => null,
                        'pada' => $absensi->diverifikasi_pada?->toISOString(),
                        'sumber' => 'sebelum_pembaruan',
                    ];
                }

                $absensi->update([
                    'foto_selfie' => $this->handleSelfieUpload($request->foto_selfie),
                    'lokasi' => $request->lokasi ?: $geoCheck['matched_office']['name'],
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'keterangan' => $request->keterangan,
                    'status_verifikasi' => 'pending',
                    'diverifikasi_oleh' => null,
                    'diverifikasi_pada' => null,
                    'riwayat_penolakan' => $riwayat,
                    'jumlah_resubmit' => (int) $absensi->jumlah_resubmit + 1,
                ]);

                $jenis = $absensi->status === 'keluar' ? 'check-out' : 'check-in';

                ActivityLogger::log(
                    'PERBAIKAN_PRESENSI',
                    "Peserta {$user->nama_lengkap} memperbaiki presensi {$jenis} ID {$absensi->id} (perbaikan ke-{$absensi->jumlah_resubmit})."
                );

                $this->notifyPembimbingPerbaikan($absensi, $user->nama_lengkap);

                return $absensi;
            });
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbaiki presensi: '.$e->getMessage(),
            ], 500);
        }

        if (! $diperbaiki) {
            return response()->json([
                'success' => false,
                'message' => 'Presensi tidak dapat diperbaiki karena statusnya sudah berubah. Muat ulang halaman dan coba lagi.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perbaikan presensi berhasil dikirim dan menunggu verifikasi pembimbing.',
            'data' => new AbsensiResource($diperbaiki->fresh([
                'unitKerja.pembimbing.pengguna',
                'unitKerja.pembimbingByUnit.pengguna',
                'pesertaMagang.pengguna',
                'pesertaMagang.unitKerja.pembimbing.pengguna',
                'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
            ])),
        ]);
    }

    /**
     * Dapatkan status absensi hari ini milik peserta yang login.
     */
    public function today(Request $request): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;

        if (! $peserta) {
            return response()->json(['success' => false, 'message' => 'Profil peserta tidak ditemukan.'], 404);
        }

        $today = Carbon::today();
        $checkIn = Absensi::where('id_peserta_magang', $peserta->id)
            ->where('status', 'masuk')
            ->whereDate('tanggal_jam', $today)
            ->first();

        $checkOut = Absensi::where('id_peserta_magang', $peserta->id)
            ->where('status', 'keluar')
            ->whereDate('tanggal_jam', $today)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'has_checked_in' => ! is_null($checkIn),
                'has_checked_out' => ! is_null($checkOut),
                'check_in' => $checkIn ? new AbsensiResource($checkIn) : null,
                'check_out' => $checkOut ? new AbsensiResource($checkOut) : null,
            ],
        ]);
    }

    /**
     * Helper untuk upload selfie via file upload atau base64.
     */
    protected function handleSelfieUpload(mixed $foto): string
    {
        if ($foto instanceof UploadedFile) {
            return $this->fileService->upload($foto, 'absensi/selfie');
        }

        return $this->fileService->uploadBase64((string) $foto, 'absensi/selfie');
    }

    private function notifyPembimbingCheckIn(PesertaMagang $peserta, string $namaPeserta): void
    {
        $idPenggunaPembimbing = $peserta->pembimbingLapangan()?->id_pengguna;

        if ($idPenggunaPembimbing === null) {
            return;
        }

        Notifikasi::create([
            'id_pengguna' => $idPenggunaPembimbing,
            'judul' => 'Presensi Baru',
            'pesan' => "Peserta {$namaPeserta} baru saja melakukan check-in. Mohon segera diverifikasi.",
            'tipe' => 'info',
            'link' => '/pembimbing/absensi.html',
        ]);
    }

    private function notifyPembimbingPerbaikan(Absensi $absensi, string $namaPeserta): void
    {
        $absensi->loadMissing(['verifikator', 'pesertaMagang']);
        $idPenggunaPembimbing = $absensi->verifikator?->id_pengguna
            ?? $absensi->pesertaMagang?->pembimbingLapangan()?->id_pengguna;

        if ($idPenggunaPembimbing === null) {
            return;
        }

        $jenis = $absensi->status === 'keluar' ? 'Check-out' : 'Check-in';
        $tanggal = $absensi->tanggal_jam?->format('d/m/Y') ?? '-';

        Notifikasi::create([
            'id_pengguna' => $idPenggunaPembimbing,
            'judul' => 'Perbaikan Presensi',
            'pesan' => "Peserta {$namaPeserta} mengirim perbaikan presensi {$jenis} tanggal {$tanggal}. Mohon verifikasi ulang.",
            'tipe' => 'warning',
            'link' => '/pembimbing/absensi.html',
            'id_absensi' => $absensi->id,
        ]);
    }
}
