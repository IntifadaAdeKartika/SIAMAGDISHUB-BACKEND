<?php

namespace App\Http\Controllers\Api\Peserta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Peserta\CheckInRequest;
use App\Http\Requests\Peserta\CheckOutRequest;
use App\Http\Resources\AbsensiResource;
use App\Models\Absensi;
use App\Models\Notifikasi;
use App\Models\PesertaMagang;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use App\Services\GeofencingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AbsensiController extends Controller
{
    public function __construct(
        protected GeofencingService $geofencing,
        protected FileUploadService $fileService
    ) {}

    /**
     * Catat absensi masuk (Check-In) peserta magang.
     */
    public function checkIn(CheckInRequest $request): JsonResponse
    {
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
            $checkInQuery = Absensi::where('id_peserta_magang', $peserta->id)
                ->where('status', 'masuk')
                ->whereDate('tanggal_jam', $today);
            $hasCheckedIn = $checkInQuery->exists();

            if ($hasCheckedIn) {
                $existingCheckIn = $checkInQuery->firstOrFail();

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

                    ActivityLogger::log('RECHECK_IN', "Peserta {$user->nama_lengkap} melakukan check-in ulang (resubmit #{ $existingCheckIn->jumlah_resubmit }).");
                    $this->notifyPembimbingCheckIn($peserta, $user->nama_lengkap);

                    return response()->json([
                        'success' => true,
                        'message' => 'Check-in ulang berhasil dicatat.',
                        'data' => new AbsensiResource($existingCheckIn->fresh(['unitKerja', 'pesertaMagang.pengguna'])),
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
                'data' => new AbsensiResource($absensi->load(['unitKerja', 'pesertaMagang.pengguna'])),
            ], 201);
        });
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

        // 2. Pastikan sudah check-in hari ini
        $today = Carbon::today();
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

        // 3. Cegah check-out ganda hari ini
        $existingCheckOut = Absensi::where('id_peserta_magang', $peserta->id)
            ->where('status', 'keluar')
            ->whereDate('tanggal_jam', $today)
            ->first();

        if ($existingCheckOut) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah melakukan check-out untuk hari ini.',
                'data' => new AbsensiResource($existingCheckOut),
            ], 422);
        }

        $fotoPath = $this->handleSelfieUpload($request->foto_selfie);
        $lokasiFinal = $request->lokasi ?: $geoCheck['matched_office']['name'];

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
            'data' => new AbsensiResource($absensi->load(['unitKerja', 'pesertaMagang.pengguna'])),
        ], 201);
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
            'has_checked_in' => ! is_null($checkIn),
            'has_checked_out' => ! is_null($checkOut),
            'check_in' => $checkIn ? new AbsensiResource($checkIn) : null,
            'check_out' => $checkOut ? new AbsensiResource($checkOut) : null,
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
        $idPenggunaPembimbing = $peserta->unitKerja?->pembimbing?->id_pengguna;

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
}
