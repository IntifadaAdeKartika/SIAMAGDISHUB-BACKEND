<?php

namespace App\Http\Controllers\Api\Peserta;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbsensiResource;
use App\Models\Absensi;
use App\Models\Notifikasi;
use App\Models\Pembimbing;
use App\Services\AbsensiRevisionService;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AbsensiRevisiController extends Controller
{
    public function __construct(
        protected AbsensiRevisionService $revisionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $peserta = $request->user()?->pesertaMagang;

        if (! $peserta) {
            return $this->profilTidakDitemukan();
        }

        $validated = $request->validate([
            'aktif' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Absensi::query()
            ->with(['unitKerja', 'verifikator.pengguna'])
            ->where('id_peserta_magang', $peserta->id)
            ->where(function ($q): void {
                $q->where('revisi_diminta', true)->orWhereNotNull('riwayat_revisi');
            });

        if ($request->boolean('aktif')) {
            $query->where('revisi_diminta', true)->where('status_verifikasi', 'pending');
        }

        $absensi = $query->orderByDesc('tanggal_jam')->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'Daftar revisi absensi Anda.',
            'data' => AbsensiResource::collection($absensi)->response()->getData(true),
        ]);
    }

    public function show(Request $request, Absensi $absensi): JsonResponse
    {
        if (! $this->milikPeserta($request, $absensi)) {
            return $this->bukanMilikAnda();
        }

        return $this->detailResponse($absensi);
    }

    public function kirim(Request $request, Absensi $absensi): JsonResponse
    {
        if (! $this->milikPeserta($request, $absensi)) {
            return $this->bukanMilikAnda();
        }

        return DB::transaction(function () use ($request, $absensi): JsonResponse {
            $absensi = Absensi::query()->whereKey($absensi->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->milikPeserta($request, $absensi)) {
                return $this->bukanMilikAnda();
            }

            $rows = $this->revisionService->kirimRevisi($absensi);
            $this->notifyPembimbing($absensi, $rows);
            $absensi = $absensi->fresh();

            ActivityLogger::log(
                'KIRIM_REVISI_ABSENSI',
                "Peserta {$request->user()->nama_lengkap} mengirim revisi absensi ID {$absensi->id}"
            );

            return response()->json([
                'success' => true,
                'message' => 'Hasil revisi berhasil dikirim dan menunggu verifikasi pembimbing.',
                'data' => [
                    'absensi' => AbsensiResource::collection($rows),
                    'status_revisi' => $absensi->statusRevisi(),
                    'riwayat_revisi' => $this->revisionService->riwayatGabungan($absensi),
                ],
            ]);
        });
    }

    private function detailResponse(Absensi $absensi): JsonResponse
    {
        $absensi = $absensi->fresh(['unitKerja', 'verifikator.pengguna']);
        $pasangan = $this->revisionService->pasangan($absensi);

        return response()->json([
            'success' => true,
            'message' => 'Detail revisi absensi.',
            'data' => [
                'absensi' => new AbsensiResource($absensi),
                'pasangan' => [
                    'check_in' => $pasangan[AbsensiRevisionService::BAGIAN_CHECK_IN]
                        ? new AbsensiResource($pasangan[AbsensiRevisionService::BAGIAN_CHECK_IN])
                        : null,
                    'check_out' => $pasangan[AbsensiRevisionService::BAGIAN_CHECK_OUT]
                        ? new AbsensiResource($pasangan[AbsensiRevisionService::BAGIAN_CHECK_OUT])
                        : null,
                ],
                'bagian_revisi_aktif' => $this->revisionService->bagianAktif($absensi),
                'riwayat_revisi' => $this->revisionService->riwayatGabungan($absensi),
            ],
        ]);
    }

    private function notifyPembimbing(Absensi $absensi, Collection $rows): void
    {
        $pembimbingId = $rows->first()?->diverifikasi_oleh;
        $pembimbing = $pembimbingId
            ? Pembimbing::with('pengguna')->find($pembimbingId)
            : $absensi->pesertaMagang?->pembimbingLapangan();

        $pembimbing?->loadMissing('pengguna');

        if (! $pembimbing?->pengguna) {
            return;
        }

        $absensi->loadMissing('pesertaMagang.pengguna');
        $labelBagian = $rows
            ->map(fn (Absensi $row) => $this->revisionService->labelBagian($row->status))
            ->implode(' dan ');
        $tanggal = $absensi->tanggal_jam?->format('d/m/Y') ?? '-';
        $namaPeserta = $absensi->pesertaMagang?->pengguna?->nama_lengkap ?? 'Peserta';

        Notifikasi::create([
            'id_pengguna' => $pembimbing->id_pengguna,
            'judul' => 'Revisi Presensi Dikirim',
            'pesan' => "{$namaPeserta} mengirim hasil revisi {$labelBagian} presensi tanggal {$tanggal}. Silakan periksa dan lakukan verifikasi.",
            'tipe' => 'info',
            'link' => '/pembimbing/absensi.html?absensi='.$absensi->id,
            'id_absensi' => $absensi->id,
        ]);
    }

    private function milikPeserta(Request $request, Absensi $absensi): bool
    {
        $peserta = $request->user()?->pesertaMagang;

        return $peserta && (int) $absensi->id_peserta_magang === (int) $peserta->id;
    }

    private function profilTidakDitemukan(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Profil peserta magang tidak ditemukan.',
        ], 404);
    }

    private function bukanMilikAnda(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Anda tidak berhak mengakses absensi ini.',
        ], 403);
    }
}
