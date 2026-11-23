<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pembimbing\ApproveIzinRequest;
use App\Http\Resources\PengajuanIzinResource;
use App\Models\Notifikasi;
use App\Models\PengajuanIzin;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;

class ApproveIzinController extends Controller
{
    /**
     * Setujui atau tolak pengajuan izin/sakit peserta magang.
     */
    public function approve(ApproveIzinRequest $request, PengajuanIzin $pengajuanIzin): JsonResponse
    {
        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing) {
            return response()->json(['success' => false, 'message' => 'Profil pembimbing tidak ditemukan.'], 404);
        }

        if (
            $pembimbing->id_unit_kerja === null
            || ! $pengajuanIzin->pesertaMagang()
                ->where('id_unit_kerja', $pembimbing->id_unit_kerja)
                ->exists()
        ) {
            return response()->json(['success' => false, 'message' => 'Pengajuan izin tidak ditemukan di unit kerja Anda.'], 404);
        }

        $pengajuanIzin->update([
            'status' => $request->status,
            'catatan_pembimbing' => $request->catatan_pembimbing,
            'diverifikasi_oleh' => $pembimbing->id,
            'diverifikasi_pada' => now(),
        ]);

        ActivityLogger::log('VERIFIKASI_IZIN', "Pembimbing merespon izin ID {$pengajuanIzin->id} dengan status: {$request->status}");

        $peserta = $pengajuanIzin->loadMissing('pesertaMagang')->pesertaMagang;
        if ($peserta) {
            $isApproved = $request->status === 'approved';
            Notifikasi::create([
                'id_pengguna' => $peserta->id_pengguna,
                'judul' => $isApproved ? 'Pengajuan Izin Disetujui' : 'Pengajuan Izin Ditolak',
                'pesan' => "Pengajuan {$pengajuanIzin->jenis} Anda telah ".($isApproved ? 'disetujui' : 'ditolak').'.',
                'tipe' => $isApproved ? 'success' : 'danger',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Pengajuan izin berhasil di-{$request->status}.",
            'data' => new PengajuanIzinResource($pengajuanIzin->fresh([
                'pesertaMagang.pengguna',
                'pesertaMagang.unitKerja.pembimbing.pengguna',
                'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
                'verifikator.pengguna',
            ])),
        ]);
    }
}
