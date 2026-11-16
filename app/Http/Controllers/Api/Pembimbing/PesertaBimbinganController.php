<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Resources\PesertaMagangResource;
use App\Models\PesertaMagang;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PesertaBimbinganController extends Controller
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

        $today = Carbon::today();

        $peserta = PesertaMagang::with([
            'pengguna',
            'unitKerja.pembimbing.pengguna',
            'absensiList' => function (HasMany $query) use ($today): void {
                $query->whereDate('tanggal_jam', $today)->latest('tanggal_jam');
            },
            'logbookList' => function (HasMany $query) use ($today): void {
                $query->whereDate('tanggal', $today)->latest('created_at')->latest('id');
            },
        ])
            ->where('id_unit_kerja', $pembimbing->id_unit_kerja)
            ->latest('updated_at')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'my_unit_kerja_id' => $pembimbing->id_unit_kerja,
            'data' => PesertaMagangResource::collection($peserta)->response()->getData(true),
        ]);
    }
}
