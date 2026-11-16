<?php

namespace App\Http\Controllers\Api\Peserta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Peserta\StorePengajuanIzinRequest;
use App\Http\Resources\PengajuanIzinResource;
use App\Models\Notifikasi;
use App\Models\PengajuanIzin;
use App\Services\ActivityLogger;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PengajuanIzinController extends Controller
{
    public function __construct(protected FileUploadService $fileService) {}

    /**
     * Update an existing izin request.
     * Allowed when the request is still pending or has been rejected (so the participant can correct it).
     */
    public function update(Request $request, PengajuanIzin $izin): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;
        if (! $peserta || $izin->id_peserta_magang !== $peserta->id) {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan izin tidak ditemukan.',
            ], 404);
        }

        if (! in_array($izin->status, ['pending', 'rejected'])) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat mengubah izin karena sudah diproses.',
            ], 422);
        }

        $validated = $request->validate([
            'jenis' => 'required|string|max:255',
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'keterangan' => 'nullable|string',
            'file_surat' => 'nullable|file|max:5120', // 5 MB max
        ]);

        // Jika ada file baru, hapus file lama dan upload yang baru
        if ($request->hasFile('file_surat')) {
            if ($izin->file_surat) {
                $this->fileService->delete($izin->file_surat);
            }
            $validated['file_surat'] = $this->fileService->upload($request->file('file_surat'), 'izin/surat');
        }

        $izin->update($validated);
        ActivityLogger::log('UPDATE_IZIN', "Peserta mengubah izin ID {$izin->id}");

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin berhasil diperbarui.',
            'data' => new PengajuanIzinResource($izin->fresh()),
        ]);
    }

    /**
     * List izin of the authenticated participant.
     */
    public function index(Request $request): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;
        if (! $peserta) {
            return response()->json(['success' => false, 'message' => 'Profil peserta tidak ditemukan.'], 404);
        }

        $izinList = PengajuanIzin::where('id_peserta_magang', $peserta->id)
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => PengajuanIzinResource::collection($izinList)->response()->getData(true),
        ]);
    }

    /**
     * Store a new izin request.
     */
    public function store(StorePengajuanIzinRequest $request): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;
        if (! $peserta) {
            return response()->json(['success' => false, 'message' => 'Profil peserta tidak ditemukan.'], 404);
        }

        $filePath = $this->fileService->upload($request->file('file_surat'), 'izin/surat');

        $izin = PengajuanIzin::create([
            'id_peserta_magang' => $peserta->id,
            'jenis' => $request->jenis,
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'keterangan' => $request->keterangan,
            'file_surat' => $filePath,
            'status' => 'pending',
        ]);

        ActivityLogger::log('AJUKAN_IZIN', "Peserta mengajukan izin {$request->jenis} ({$request->tanggal_mulai} s/d {$request->tanggal_selesai})");

        $idPenggunaPembimbing = $peserta->unitKerja?->pembimbing?->id_pengguna;

        if ($idPenggunaPembimbing !== null) {
            Notifikasi::create([
                'id_pengguna' => $idPenggunaPembimbing,
                'judul' => 'Pengajuan Izin Baru',
                'pesan' => "Peserta {$request->user()->nama_lengkap} mengajukan {$izin->jenis} untuk tanggal {$izin->tanggal_mulai->toDateString()} s/d {$izin->tanggal_selesai->toDateString()}.",
                'tipe' => 'warning',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin berhasil dikirim dan menunggu persetujuan pembimbing.',
            'data' => new PengajuanIzinResource($izin),
        ], 201);
    }

    /**
     * Cancel an izin request.
     * Can cancel while still pending or even after it was rejected (so the participant can restart the process).
     */
    public function cancel(Request $request, PengajuanIzin $izin): JsonResponse
    {
        $peserta = $request->user()->pesertaMagang;
        if (! $peserta || $izin->id_peserta_magang !== $peserta->id) {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan izin tidak ditemukan.',
            ], 404);
        }

        if (! in_array($izin->status, ['pending', 'rejected'])) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat dibatalkan karena status sudah diproses.',
            ], 422);
        }

        $izin->update(['status' => 'cancelled']);
        ActivityLogger::log('CANCEL_IZIN', "Peserta membatalkan izin ID {$izin->id}");

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin dibatalkan.',
        ]);
    }
}
