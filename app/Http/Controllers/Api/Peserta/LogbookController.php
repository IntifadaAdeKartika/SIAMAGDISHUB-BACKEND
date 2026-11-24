<?php

namespace App\Http\Controllers\Api\Peserta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Peserta\StoreLogbookRequest;
use App\Http\Requests\Peserta\UpdateLogbookRequest;
use App\Http\Resources\LogbookResource;
use App\Models\Absensi;
use App\Models\Logbook;
use App\Models\Notifikasi;
use App\Models\PengajuanIzin;
use App\Models\PesertaMagang;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogbookController extends Controller
{
    public function __construct(
        protected FileUploadService $fileService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;

        if (! $peserta) {
            return response()->json(['success' => false, 'message' => 'Profil peserta tidak ditemukan.'], 404);
        }

        $logbook = Logbook::where('id_peserta_magang', $peserta->id)
            ->latest('tanggal')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => LogbookResource::collection($logbook)->response()->getData(true),
        ]);
    }

    public function store(StoreLogbookRequest $request): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;

        if (! $peserta) {
            return response()->json(['success' => false, 'message' => 'Profil peserta tidak ditemukan.'], 404);
        }

        $fotoKegiatanPath = null;
        if ($request->has('foto_kegiatan') && ! empty($request->foto_kegiatan)) {
            if ($request->file('foto_kegiatan')) {
                $fotoKegiatanPath = $this->fileService->upload($request->file('foto_kegiatan'), 'logbook/foto');
            } else {
                $fotoKegiatanPath = $this->fileService->uploadBase64((string) $request->foto_kegiatan, 'logbook/foto');
            }
        }

        // Business rule: if not a draft, ensure today's absensi approved and within 24h unless approved izin exists
        if (! $request->input('is_draft', false)) {
            // Find today's approved absensi for participant
            $today = now()->toDateString();
            $absensi = Absensi::where('id_peserta_magang', $peserta->id)
                ->whereDate('tanggal_jam', $today)
                ->where('status_verifikasi', 'approved')
                ->first();

            if (! $absensi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada absensi yang disetujui untuk hari ini.',
                ], 422);
            }

            // Check if there is an approved izin covering today
            $hasApprovedIzin = PengajuanIzin::where('id_peserta_magang', $peserta->id)
                ->where('status', 'approved')
                ->whereDate('tanggal_mulai', '<=', $today)
                ->whereDate('tanggal_selesai', '>=', $today)
                ->exists();

            if (! $hasApprovedIzin) {
                $verifiedAt = $absensi->diverifikasi_pada;
                if ($verifiedAt && now()->diffInHours($verifiedAt) > 24) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Waktu pengisian logbook habis (melebihi 24 jam sejak disetujui)',
                    ], 422);
                }
            }
        }

        $logbook = Logbook::create([
            'id_peserta_magang' => $peserta->id,
            'id_absensi' => $request->id_absensi,
            'tanggal' => $request->tanggal,
            'jam_mulai' => $request->jam_mulai,
            'jam_selesai' => $request->jam_selesai,
            'kegiatan' => $request->kegiatan,
            'foto_kegiatan' => $fotoKegiatanPath,
            'status_verifikasi' => 'pending',
            'is_draft' => $request->input('is_draft', false),
        ]);

        ActivityLogger::log('CREATE_LOGBOOK', "Peserta mengisi logbook tanggal {$request->tanggal}");

        if (! $logbook->is_draft) {
            $idPenggunaPembimbing = $peserta->pembimbingLapangan()?->id_pengguna;

            if ($idPenggunaPembimbing !== null) {
                Notifikasi::create([
                    'id_pengguna' => $idPenggunaPembimbing,
                    'judul' => 'Logbook Baru',
                    'pesan' => "Peserta {$request->user()->nama_lengkap} telah mengisi logbook untuk tanggal {$logbook->tanggal->toDateString()}. Mohon diverifikasi.",
                    'tipe' => 'info',
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logbook harian berhasil disimpan.',
            'data' => new LogbookResource($logbook),
        ], 201);
    }

    public function show(Request $request, Logbook $logbook): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;

        if (! $peserta || $logbook->id_peserta_magang !== $peserta->id) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Anda tidak berhak melihat logbook ini.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new LogbookResource($logbook),
        ]);
    }

    public function update(UpdateLogbookRequest $request, Logbook $logbook): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;

        if (! $peserta || $logbook->id_peserta_magang !== $peserta->id) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Anda tidak berhak mengubah logbook ini.',
            ], 403);
        }

        // ATURAN BISNIS: hanya logbook berstatus pending (termasuk yang diminta revisi) yang dapat diedit
        if (! $logbook->dapatDieditPeserta()) {
            return response()->json([
                'success' => false,
                'message' => "Logbook tidak dapat diubah karena telah diverifikasi ({$logbook->status_verifikasi}).",
            ], 422);
        }

        $menungguRevisi = $logbook->menungguRevisi();

        $validated = $request->validated();

        if ($request->hasFile('foto_kegiatan')) {
            $this->fileService->delete($logbook->foto_kegiatan);
            $validated['foto_kegiatan'] = $this->fileService->upload($request->file('foto_kegiatan'), 'logbook/foto');
        } elseif ($request->filled('foto_kegiatan') && is_string($request->foto_kegiatan)) {
            $incoming = trim($request->foto_kegiatan);
            $currentPath = (string) $logbook->foto_kegiatan;
            $currentUrl = (string) $this->fileService->url($logbook->foto_kegiatan);

            $isSameAsCurrent = ($incoming === $currentPath || $incoming === $currentUrl);
            $isExistingUrl = str_starts_with($incoming, 'http://')
                || str_starts_with($incoming, 'https://')
                || str_starts_with($incoming, '/storage/');

            if (! $isSameAsCurrent && ! $isExistingUrl) {
                // Peserta mengunggah foto baru via Base64
                $this->fileService->delete($logbook->foto_kegiatan);
                $validated['foto_kegiatan'] = $this->fileService->uploadBase64($incoming, 'logbook/foto');
            } else {
                // Pertahankan foto lama jika mengirimkan path atau URL foto lama
                unset($validated['foto_kegiatan']);
            }
        } else {
            // Field foto tidak dikirim atau null, pertahankan foto lama
            unset($validated['foto_kegiatan']);
        }

        $logbook->update($validated);

        if ($menungguRevisi) {
            $riwayat = $logbook->riwayat_revisi ?? [];

            if ($riwayat !== []) {
                $lastKey = array_key_last($riwayat);
                $riwayat[$lastKey]['dikirim_pada'] = now()->toISOString();
            }

            $logbook->forceFill([
                'status_verifikasi' => 'pending',
                'revisi_diminta' => false,
                'revisi_dikirim_pada' => now(),
                'riwayat_revisi' => $riwayat,
            ])->save();

            $this->notifyPembimbingAboutRevision($logbook, $peserta, $request->user()->nama_lengkap);

            ActivityLogger::log('KIRIM_REVISI_LOGBOOK', "Peserta mengirimkan revisi logbook ID: {$logbook->id}");
        }

        ActivityLogger::log('UPDATE_LOGBOOK', "Peserta memperbarui logbook ID: {$logbook->id}");

        return response()->json([
            'success' => true,
            'message' => $menungguRevisi ? 'Revisi logbook berhasil dikirim.' : 'Logbook berhasil diperbarui.',
            'data' => new LogbookResource($logbook->fresh()),
        ]);
    }

    private function notifyPembimbingAboutRevision(Logbook $logbook, PesertaMagang $peserta, string $namaPeserta): void
    {
        $idPenggunaPembimbing = $logbook->verifikator?->id_pengguna
            ?? $peserta->pembimbingLapangan()?->id_pengguna;

        if ($idPenggunaPembimbing === null) {
            return;
        }

        $tanggal = $logbook->tanggal->toDateString();

        Notifikasi::create([
            'id_pengguna' => $idPenggunaPembimbing,
            'judul' => 'Revisi Logbook Dikirim',
            'pesan' => "Peserta {$namaPeserta} telah mengirimkan revisi logbook untuk tanggal {$tanggal}. Mohon diperiksa kembali.",
            'tipe' => 'info',
            'link' => '/pembimbing/logbook.html',
        ]);
    }
}
