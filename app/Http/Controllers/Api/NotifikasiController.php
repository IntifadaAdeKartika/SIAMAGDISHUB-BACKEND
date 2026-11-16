<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotifikasiResource;
use App\Models\Notifikasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotifikasiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifikasi = Notifikasi::where('id_pengguna', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => NotifikasiResource::collection($notifikasi)->resolve(),
        ]);
    }

    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notifikasi = Notifikasi::where('id_pengguna', $request->user()->id)
            ->findOrFail($id);
        $notifikasi->update(['sudah_dibaca' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Notifikasi berhasil ditandai sebagai dibaca.',
            'data' => (new NotifikasiResource($notifikasi->fresh()))->resolve(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $updatedCount = Notifikasi::where('id_pengguna', $request->user()->id)
            ->where('sudah_dibaca', false)
            ->update(['sudah_dibaca' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Semua notifikasi berhasil ditandai sebagai dibaca.',
            'data' => [
                'updated_count' => $updatedCount,
            ],
        ]);
    }
}
