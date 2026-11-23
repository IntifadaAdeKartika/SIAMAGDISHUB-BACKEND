<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUnitKerjaRequest;
use App\Http\Requests\Admin\UpdateUnitKerjaRequest;
use App\Http\Resources\UnitKerjaResource;
use App\Models\Pembimbing;
use App\Models\UnitKerja;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitKerjaController extends Controller
{
    public function listForFilter(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => UnitKerja::query()
                ->orderBy('nama_unit')
                ->get(['id', 'nama_unit']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = UnitKerja::with(['induk', 'pembimbing.pengguna', 'pembimbingByUnit.pengguna']);

        if ($request->filled('tipe')) {
            $query->where('tipe', $request->tipe);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nama_unit', 'ilike', "%{$search}%")
                    ->orWhere('kode_unit', 'ilike', "%{$search}%");
            });
        }

        $units = $query
            ->latest('updated_at')
            ->orderByDesc('id')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => UnitKerjaResource::collection($units)->response()->getData(true),
        ]);
    }

    public function store(StoreUnitKerjaRequest $request): JsonResponse
    {
        $validated = $request->validated();

        \DB::beginTransaction();
        try {
            // Lepas pembimbing dari unit lain lebih dulu agar tidak melanggar UNIQUE.
            if (! empty($validated['id_pembimbing'])) {
                UnitKerja::where('id_pembimbing', $validated['id_pembimbing'])->update(['id_pembimbing' => null]);
            }

            $unit = UnitKerja::create($validated);

            // Sinkronisasi relasi pembimbing
            if (! empty($unit->id_pembimbing)) {
                // Set unit kerja di tabel pembimbing
                Pembimbing::where('id', $unit->id_pembimbing)->update(['id_unit_kerja' => $unit->id]);
            }

            ActivityLogger::log('CREATE_UNIT_KERJA', "Admin membuat unit kerja: {$unit->nama_unit}");
            \DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Unit kerja berhasil dibuat.',
                'data' => new UnitKerjaResource($unit->load(['induk', 'pembimbing.pengguna', 'pembimbingByUnit.pengguna'])),
            ], 201);
        } catch (\Exception $e) {
            \DB::rollBack();

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show(UnitKerja $unitKerja): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new UnitKerjaResource($unitKerja->load(['induk', 'pembimbing.pengguna', 'pembimbingByUnit.pengguna', 'subUnit'])),
        ]);
    }

    public function update(UpdateUnitKerjaRequest $request, UnitKerja $unitKerja): JsonResponse
    {
        $validated = $request->validated();

        \DB::beginTransaction();
        try {
            $oldPembimbingId = $unitKerja->id_pembimbing;

            // Lepas pembimbing baru dari unit lain lebih dulu agar tidak bentrok UNIQUE.
            if (! empty($validated['id_pembimbing'])) {
                UnitKerja::where('id_pembimbing', $validated['id_pembimbing'])
                    ->where('id', '!=', $unitKerja->id)
                    ->update(['id_pembimbing' => null]);
            }

            $unitKerja->update($validated);

            $newPembimbingId = $unitKerja->id_pembimbing;

            // Jika pembimbing diganti/dikosongkan, lepaskan dari unit lama.
            if ($oldPembimbingId && $oldPembimbingId != $newPembimbingId) {
                Pembimbing::where('id', $oldPembimbingId)->update(['id_unit_kerja' => null]);
            }

            // Set unit kerja di tabel pembimbing.
            if (! empty($newPembimbingId)) {
                Pembimbing::where('id', $newPembimbingId)->update(['id_unit_kerja' => $unitKerja->id]);
            }

            ActivityLogger::log('UPDATE_UNIT_KERJA', "Admin memperbarui unit kerja: {$unitKerja->nama_unit}");
            \DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Unit kerja berhasil diperbarui.',
                'data' => new UnitKerjaResource($unitKerja->fresh(['induk', 'pembimbing.pengguna', 'pembimbingByUnit.pengguna'])),
            ]);
        } catch (\Exception $e) {
            \DB::rollBack();

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(UnitKerja $unitKerja): JsonResponse
    {
        $nama = $unitKerja->nama_unit;
        $unitKerja->delete();

        ActivityLogger::log('DELETE_UNIT_KERJA', "Admin menghapus unit kerja: {$nama}");

        return response()->json([
            'success' => true,
            'message' => 'Unit kerja berhasil dihapus.',
        ]);
    }
}
