<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PengajuanIzinResource;
use App\Models\PengajuanIzin;
use Illuminate\Http\JsonResponse;

class AdminPengajuanIzinController extends Controller
{
    public function index(): JsonResponse
    {
        $pengajuanIzin = PengajuanIzin::with([
            'pesertaMagang.pengguna',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
            'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
            'verifikator.pengguna',
        ])
            ->latest()
            ->paginate(15)
            ->through(fn (PengajuanIzin $izin): array => (new PengajuanIzinResource($izin))->resolve());

        return response()->json([
            'success' => true,
            'data' => $pengajuanIzin,
        ]);
    }
}
