<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbsensiListResource;
use App\Http\Resources\PesertaMagangResource;
use App\Models\Absensi;
use App\Models\PesertaMagang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MonitoringController extends Controller
{
    public function rekapAbsensi(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tanggal_mulai' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing || ! $pembimbing->id_unit_kerja) {
            return response()->json([
                'success' => false,
                'message' => 'Profil atau unit kerja pembimbing tidak ditemukan.',
            ], 404);
        }

        $query = Absensi::with([
            'pesertaMagang.pengguna',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
            'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
            'unitKerja.pembimbing.pengguna',
            'unitKerja.pembimbingByUnit.pengguna',
        ])
            ->where('id_unit_kerja', $pembimbing->id_unit_kerja)
            ->whereHas('pesertaMagang', function (Builder $pesertaQuery) use ($pembimbing): void {
                $pesertaQuery->where('id_unit_kerja', $pembimbing->id_unit_kerja);
            });

        if (isset($filters['tanggal_mulai'])) {
            $query->whereDate('tanggal_jam', '>=', $filters['tanggal_mulai']);
        }

        if (isset($filters['tanggal_selesai'])) {
            $query->whereDate('tanggal_jam', '<=', $filters['tanggal_selesai']);
        }

        $perPage = min(25, max(1, (int) ($filters['per_page'] ?? 20)));
        $absensi = $query
            ->latest('tanggal_jam')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'my_unit_kerja_id' => $pembimbing->id_unit_kerja,
            'data' => AbsensiListResource::collection($absensi)->response()->getData(true),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing || ! $pembimbing->id_unit_kerja) {
            return response()->json([
                'success' => false,
                'message' => 'Profil atau unit kerja pembimbing tidak ditemukan.',
            ], 404);
        }

        $today = Carbon::today();
        $query = PesertaMagang::with([
            'pengguna',
            'unitKerja.pembimbing.pengguna',
            'unitKerja.pembimbingByUnit.pengguna',
            'absensiList' => function (HasMany $query) use ($today): void {
                $query->whereDate('tanggal_jam', $today)->latest('tanggal_jam');
            },
            'logbookList' => function (HasMany $query) use ($today): void {
                $query->whereDate('tanggal', $today)->latest('created_at')->latest('id');
            },
        ])->where('id_unit_kerja', $pembimbing->id_unit_kerja);

        if ($request->filled('status_verifikasi')) {
            $query->whereHas('absensiList', function (Builder $absensiQuery) use ($request, $today): void {
                $absensiQuery
                    ->whereDate('tanggal_jam', $today)
                    ->where('status_verifikasi', $request->input('status_verifikasi'));
            });
        }

        $peserta = $query->latest('updated_at')->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'my_unit_kerja_id' => $pembimbing->id_unit_kerja,
            'data' => PesertaMagangResource::collection($peserta)->response()->getData(true),
        ]);
    }
}
