<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbsensiResource;
use App\Http\Resources\LogbookResource;
use App\Models\Absensi;
use App\Models\Logbook;
use App\Models\Pembimbing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class LaporanPembimbingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'jenis_laporan' => ['required', 'in:presensi,kehadiran,logbook'],
            'peserta_id' => ['nullable', 'integer'],
            'tanggal_mulai' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (
            isset($filters['tanggal_mulai'], $filters['tanggal_selesai'])
            && $filters['tanggal_selesai'] < $filters['tanggal_mulai']
        ) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Tanggal selesai harus sama atau setelah tanggal mulai.',
            ]);
        }

        $pembimbing = $request->user()->pembimbing;

        if (! $pembimbing || ! $pembimbing->id_unit_kerja) {
            return response()->json([
                'success' => false,
                'message' => 'Profil atau unit kerja pembimbing tidak ditemukan.',
            ], 404);
        }

        return match ($filters['jenis_laporan']) {
            'presensi', 'kehadiran' => $this->attendanceReport($request, $pembimbing, $filters),
            'logbook' => $this->logbookReport($request, $pembimbing, $filters),
        };
    }

    /**
     * @param  array{peserta_id?: int|string, tanggal_mulai?: string, tanggal_selesai?: string, per_page?: int|string}  $filters
     */
    private function attendanceReport(Request $request, Pembimbing $pembimbing, array $filters): JsonResponse
    {
        $query = Absensi::with([
            'pesertaMagang.pengguna',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
            'unitKerja',
            'verifikator.pengguna',
        ]);

        $this->applyParticipantScope($query, $pembimbing, $filters);
        $this->applyDateFilters($query, 'tanggal_jam', $filters);

        $records = $query
            ->latest('tanggal_jam')
            ->paginate($filters['per_page'] ?? 20);

        return $this->reportResponse(
            'presensi',
            AbsensiResource::collection($records->getCollection())->resolve($request),
            $records,
        );
    }

    /**
     * @param  array{peserta_id?: int|string, tanggal_mulai?: string, tanggal_selesai?: string, per_page?: int|string}  $filters
     */
    private function logbookReport(Request $request, Pembimbing $pembimbing, array $filters): JsonResponse
    {
        $query = Logbook::with([
            'pesertaMagang.pengguna',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
            'verifikator.pengguna',
        ]);

        $this->applyParticipantScope($query, $pembimbing, $filters);
        $this->applyDateFilters($query, 'tanggal', $filters);

        $records = $query
            ->latest('tanggal')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);

        return $this->reportResponse(
            'logbook',
            LogbookResource::collection($records->getCollection())->resolve($request),
            $records,
        );
    }

    /**
     * @param  array{peserta_id?: int|string, tanggal_mulai?: string, tanggal_selesai?: string, per_page?: int|string}  $filters
     */
    private function applyParticipantScope(Builder $query, Pembimbing $pembimbing, array $filters): void
    {
        $query->whereHas('pesertaMagang', function (Builder $participantQuery) use ($pembimbing, $filters): void {
            $participantQuery->where('id_unit_kerja', $pembimbing->id_unit_kerja);

            if (isset($filters['peserta_id'])) {
                $participantQuery->whereKey($filters['peserta_id']);
            }
        });
    }

    /**
     * @param  array{peserta_id?: int|string, tanggal_mulai?: string, tanggal_selesai?: string, per_page?: int|string}  $filters
     */
    private function applyDateFilters(Builder $query, string $column, array $filters): void
    {
        if (isset($filters['tanggal_mulai'])) {
            $query->whereDate($column, '>=', $filters['tanggal_mulai']);
        }

        if (isset($filters['tanggal_selesai'])) {
            $query->whereDate($column, '<=', $filters['tanggal_selesai']);
        }
    }

    private function reportResponse(
        string $jenisLaporan,
        array $data,
        LengthAwarePaginator $records,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'jenis_laporan' => $jenisLaporan,
            'data' => $data,
            'pagination' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }
}
