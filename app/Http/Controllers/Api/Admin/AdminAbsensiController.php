<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbsensiListResource;
use App\Models\Absensi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAbsensiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tanggal_mulai' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['nullable', 'date_format:Y-m-d'],
            'id_peserta_magang' => ['nullable', 'integer'],
            'status_verifikasi' => ['nullable', 'in:pending,approved,rejected'],
            'status_revisi' => ['nullable', 'in:menunggu_revisi,menunggu_verifikasi,disetujui,ditolak'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Absensi::with([
            'pesertaMagang.pengguna',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
            'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
            'unitKerja.pembimbing.pengguna',
            'unitKerja.pembimbingByUnit.pengguna',
            'verifikator.pengguna',
        ]);

        if (! empty($validated['tanggal_mulai'])) {
            $query->whereDate('tanggal_jam', '>=', $validated['tanggal_mulai']);
        }

        if (! empty($validated['tanggal_selesai'])) {
            $query->whereDate('tanggal_jam', '<=', $validated['tanggal_selesai']);
        }

        if (! empty($validated['id_peserta_magang'])) {
            $query->where('id_peserta_magang', $validated['id_peserta_magang']);
        }

        if (! empty($validated['status_verifikasi'])) {
            $query->where('status_verifikasi', $validated['status_verifikasi']);
        }

        if (! empty($validated['search'])) {
            $query->whereHas('pesertaMagang.pengguna', function ($q) use ($validated): void {
                $q->where('nama_lengkap', 'like', '%'.$validated['search'].'%');
            });
        }

        match ($validated['status_revisi'] ?? null) {
            'menunggu_revisi' => $query->where('status_verifikasi', 'pending')->where('revisi_diminta', true),
            'menunggu_verifikasi' => $query->where('status_verifikasi', 'pending')
                ->where('revisi_diminta', false)
                ->whereNotNull('revisi_dikirim_pada'),
            'disetujui' => $query->where('status_verifikasi', 'approved'),
            'ditolak' => $query->where('status_verifikasi', 'rejected'),
            default => null,
        };

        $perPage = min(25, max(1, (int) ($validated['per_page'] ?? 20)));
        $absensi = $query->latest('tanggal_jam')->paginate($perPage)->withQueryString()
            ->through(fn (Absensi $row): array => (new AbsensiListResource($row))->resolve());

        return response()->json([
            'success' => true,
            'data' => $absensi,
        ]);
    }
}
