<?php

namespace App\Http\Controllers\Api\Peserta;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbsensiListResource;
use App\Http\Resources\LogbookResource;
use App\Http\Resources\PengajuanIzinResource;
use App\Models\Absensi;
use App\Models\Logbook;
use App\Models\PengajuanIzin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiwayatController extends Controller
{
    /**
     * Tampilkan riwayat absensi, logbook, dan pengajuan izin milik peserta.
     */
    public function index(Request $request): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;

        if (! $peserta) {
            return response()->json(['success' => false, 'message' => 'Profil peserta tidak ditemukan.'], 404);
        }

        $limit = min(25, max(1, (int) $request->input('limit', 10)));

        $absensi = Absensi::where('id_peserta_magang', $peserta->id)
            ->latest('tanggal_jam')
            ->limit($limit)
            ->get();

        $logbook = Logbook::where('id_peserta_magang', $peserta->id)
            ->latest('tanggal')
            ->limit($limit)
            ->get();

        $izin = PengajuanIzin::where('id_peserta_magang', $peserta->id)
            ->latest()
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'riwayat_absensi' => AbsensiListResource::collection($absensi),
                'riwayat_logbook' => LogbookResource::collection($logbook),
                'riwayat_izin' => PengajuanIzinResource::collection($izin),
            ],
        ]);
    }
}
