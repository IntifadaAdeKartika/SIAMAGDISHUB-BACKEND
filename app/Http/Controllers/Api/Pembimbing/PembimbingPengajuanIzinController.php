<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Resources\PengajuanIzinResource;
use App\Models\PengajuanIzin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PembimbingPengajuanIzinController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing || ! $pembimbing->id_unit_kerja) {
            return response()->json([
                'success' => false,
                'message' => 'Profil atau unit kerja pembimbing tidak ditemukan.',
            ], 404);
        }

        $pengajuanIzin = PengajuanIzin::with([
            'pesertaMagang.pengguna',
            'pesertaMagang.unitKerja',
            'verifikator.pengguna',
        ])
            ->whereHas('pesertaMagang', function (Builder $query) use ($pembimbing): void {
                $query->where('id_unit_kerja', $pembimbing->id_unit_kerja);
            })
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'my_unit_kerja_id' => $pembimbing->id_unit_kerja,
            'data' => PengajuanIzinResource::collection($pengajuanIzin)->response()->getData(true),
        ]);
    }
}
