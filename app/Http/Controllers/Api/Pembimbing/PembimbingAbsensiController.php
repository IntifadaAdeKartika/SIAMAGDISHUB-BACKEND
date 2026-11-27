<?php

namespace App\Http\Controllers\Api\Pembimbing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pembimbing\MintaRevisiAbsensiRequest;
use App\Http\Resources\AbsensiResource;
use App\Models\Absensi;
use App\Models\Notifikasi;
use App\Models\Pembimbing;
use App\Services\AbsensiRevisionService;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PembimbingAbsensiController extends Controller
{
    public function __construct(
        protected AbsensiRevisionService $revisionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $pembimbing = $this->pembimbingAktif($request);

        if (! $pembimbing) {
            return $this->profilTidakDitemukan();
        }

        $validated = $request->validate([
            'tanggal_mulai' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['nullable', 'date_format:Y-m-d'],
            'id_peserta_magang' => ['nullable', 'integer'],
            'status_verifikasi' => ['nullable', 'in:pending,approved,rejected'],
            'status_revisi' => ['nullable', 'in:menunggu_revisi,menunggu_verifikasi,disetujui,ditolak'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Absensi::query()
            ->with([
                'pesertaMagang.pengguna',
                'pesertaMagang.unitKerja.pembimbing.pengguna',
                'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
                'unitKerja.pembimbing.pengguna',
                'unitKerja.pembimbingByUnit.pengguna',
                'verifikator.pengguna',
            ])
            ->where('id_unit_kerja', $pembimbing->id_unit_kerja)
            ->whereHas('pesertaMagang', fn ($q) => $q->where('id_unit_kerja', $pembimbing->id_unit_kerja));

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

        match ($validated['status_revisi'] ?? null) {
            'menunggu_revisi' => $query->where('status_verifikasi', 'pending')->where('revisi_diminta', true),
            'menunggu_verifikasi' => $query->where('status_verifikasi', 'pending')
                ->where('revisi_diminta', false)
                ->whereNotNull('revisi_dikirim_pada'),
            'disetujui' => $query->where('status_verifikasi', 'approved'),
            'ditolak' => $query->where('status_verifikasi', 'rejected'),
            default => null,
        };

        $absensi = $query->orderByDesc('tanggal_jam')->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'Daftar absensi peserta bimbingan.',
            'data' => AbsensiResource::collection($absensi)->response()->getData(true),
        ]);
    }

    public function show(Request $request, Absensi $absensi): JsonResponse
    {
        $pembimbing = $this->pembimbingAktif($request);

        if (! $pembimbing) {
            return $this->profilTidakDitemukan();
        }

        if (! $this->dalamScope($absensi, $pembimbing)) {
            return $this->tidakDitemukan();
        }

        return $this->detailResponse($absensi);
    }

    public function riwayat(Request $request, Absensi $absensi): JsonResponse
    {
        $pembimbing = $this->pembimbingAktif($request);

        if (! $pembimbing) {
            return $this->profilTidakDitemukan();
        }

        if (! $this->dalamScope($absensi, $pembimbing)) {
            return $this->tidakDitemukan();
        }

        return $this->detailResponse($absensi);
    }

    public function mintaRevisi(MintaRevisiAbsensiRequest $request, Absensi $absensi): JsonResponse
    {
        $pembimbing = $this->pembimbingAktif($request);

        if (! $pembimbing) {
            return $this->profilTidakDitemukan();
        }

        if (! $this->dalamScope($absensi, $pembimbing)) {
            return $this->tidakDitemukan();
        }

        $validated = $request->validated();
        $catatan = trim((string) ($validated['catatan'] ?? ''))
            ?: trim((string) ($validated['catatan_pembimbing'] ?? ''));

        if ($catatan === '') {
            throw ValidationException::withMessages([
                'catatan' => ['Catatan revisi wajib diisi.'],
            ]);
        }

        return DB::transaction(function () use ($request, $absensi, $pembimbing, $validated, $catatan): JsonResponse {
            $absensi = Absensi::query()->whereKey($absensi->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->dalamScope($absensi, $pembimbing)) {
                return $this->tidakDitemukan();
            }

            $target = $this->revisionService->mintaRevisi(
                $absensi,
                (string) $validated['bagian'],
                $catatan,
                $pembimbing,
                $request->user()->nama_lengkap
            );

            $labelBagian = $this->revisionService->labelBagian((string) $validated['bagian']);
            $this->notifyPesertaRevisi($absensi, $labelBagian, $catatan);

            ActivityLogger::log(
                'MINTA_REVISI_ABSENSI',
                "Pembimbing {$request->user()->nama_lengkap} meminta revisi {$labelBagian} absensi ID {$absensi->id}: {$catatan}"
            );

            return response()->json([
                'success' => true,
                'message' => "Permintaan revisi {$labelBagian} berhasil dikirim kepada peserta.",
                'data' => [
                    'bagian' => $validated['bagian'],
                    'absensi' => AbsensiResource::collection($target),
                    'riwayat_revisi' => $this->revisionService->riwayatGabungan($absensi->fresh()),
                ],
            ]);
        });
    }

    private function detailResponse(Absensi $absensi): JsonResponse
    {
        $absensi = $absensi->fresh([
            'pesertaMagang.pengguna',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
            'pesertaMagang.unitKerja.pembimbingByUnit.pengguna',
            'unitKerja.pembimbing.pengguna',
            'unitKerja.pembimbingByUnit.pengguna',
            'verifikator.pengguna',
        ]);

        $pasangan = $this->revisionService->pasangan($absensi);

        return response()->json([
            'success' => true,
            'message' => 'Detail absensi peserta bimbingan.',
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

    private function notifyPesertaRevisi(Absensi $absensi, string $labelBagian, string $catatan): void
    {
        $peserta = $absensi->loadMissing('pesertaMagang')->pesertaMagang;

        if (! $peserta) {
            return;
        }

        $tanggal = $absensi->tanggal_jam?->format('d/m/Y') ?? '-';

        Notifikasi::create([
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Presensi Perlu Direvisi',
            'pesan' => "Pembimbing meminta revisi {$labelBagian} presensi Anda tanggal {$tanggal}. Catatan: {$catatan}. Silakan perbaiki melalui menu absensi.",
            'tipe' => 'warning',
            'link' => '/peserta/absensi.html?revisi='.$absensi->id,
            'id_absensi' => $absensi->id,
        ]);
    }

    private function pembimbingAktif(Request $request): ?Pembimbing
    {
        $pembimbing = $request->user()?->pembimbing;

        return $pembimbing && $pembimbing->id_unit_kerja !== null ? $pembimbing : null;
    }

    private function dalamScope(Absensi $absensi, Pembimbing $pembimbing): bool
    {
        return $absensi->pesertaMagang()
            ->where('id_unit_kerja', $pembimbing->id_unit_kerja)
            ->exists();
    }

    private function profilTidakDitemukan(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Profil pembimbing atau unit kerja tidak ditemukan.',
        ], 404);
    }

    private function tidakDitemukan(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Absensi tidak ditemukan di unit kerja Anda.',
        ], 404);
    }
}
