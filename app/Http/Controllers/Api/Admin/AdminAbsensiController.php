<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbsensiResource;
use App\Models\Absensi;
use Illuminate\Http\JsonResponse;

class AdminAbsensiController extends Controller
{
    public function index(): JsonResponse
    {
        $absensi = Absensi::with([
            'pesertaMagang.pengguna',
            'unitKerja.pembimbing.pengguna',
            'verifikator.pengguna',
        ])
            ->latest('tanggal_jam')
            ->paginate(15)
            ->through(fn (Absensi $row): array => (new AbsensiResource($row))->resolve());

        return response()->json([
            'success' => true,
            'data' => $absensi,
        ]);
    }
}
