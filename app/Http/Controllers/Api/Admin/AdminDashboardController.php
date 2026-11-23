<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PengajuanIzinResource;
use App\Models\Absensi;
use App\Models\Pembimbing;
use App\Models\PengajuanIzin;
use App\Models\PesertaMagang;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today = now()->toDateString();
        $izinTerbaru = PengajuanIzin::with([
            'pesertaMagang.pengguna',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
            'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
        ])
            ->latest()
            ->limit(5)
            ->get();
        $detailAbsensiHariIni = Absensi::with(['pesertaMagang.pengguna', 'unitKerja'])
            ->whereDate('tanggal_jam', $today)
            ->latest('tanggal_jam')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'jumlah_peserta' => PesertaMagang::count(),
                'jumlah_pembimbing' => Pembimbing::count(),
                'total_absensi_hari_ini' => Absensi::whereDate('tanggal_jam', $today)->count(),
                'detail_absensi_hari_ini' => $detailAbsensiHariIni->map(fn (Absensi $absensi): array => [
                    'id' => $absensi->id,
                    'nama_peserta' => $absensi->pesertaMagang?->pengguna?->nama_lengkap,
                    'nama_unit' => $absensi->unitKerja?->nama_unit,
                    'unit_kerja' => $absensi->unitKerja
                        ? ['nama_unit' => $absensi->unitKerja->nama_unit]
                        : null,
                    'tanggal_jam' => $absensi->tanggal_jam?->toISOString(),
                    'status' => $absensi->status,
                    'status_verifikasi' => $absensi->status_verifikasi,
                    'lokasi' => $absensi->lokasi,
                ])->values(),
                'izin_terbaru' => PengajuanIzinResource::collection($izinTerbaru)->resolve(),
            ],
        ]);
    }
}
