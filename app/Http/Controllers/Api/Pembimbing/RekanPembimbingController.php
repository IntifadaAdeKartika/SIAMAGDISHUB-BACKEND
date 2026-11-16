<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Resources\PembimbingResource;
use App\Models\Pembimbing;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

class RekanPembimbingController extends Controller
{
    public function index(): JsonResponse
    {
        $pembimbingList = Pembimbing::with([
            'pengguna',
            'unitKerja',
            'pesertaBimbingan' => function (HasMany $query): void {
                $query
                    ->select(['id', 'id_pengguna', 'id_unit_kerja', 'asal_sekolah_kampus'])
                    ->with('pengguna:id,nama_lengkap');
            },
        ])
            ->withCount(['pesertaBimbingan as total_bimbingan'])
            ->orderBy('id')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => PembimbingResource::collection($pembimbingList)->response()->getData(true),
        ]);
    }
}
