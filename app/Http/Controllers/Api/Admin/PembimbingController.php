<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePembimbingRequest;
use App\Http\Requests\Admin\UpdatePembimbingRequest;
use App\Http\Resources\PembimbingResource;
use App\Models\Pembimbing;
use App\Models\Pengguna;
use App\Models\UnitKerja;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PembimbingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Pembimbing::with(['pengguna', 'unitKerja.pembimbing.pengguna', 'unitKerja.pembimbingByUnit.pengguna']);

        if ($request->filled('id_unit_kerja')) {
            $query->where('id_unit_kerja', $request->id_unit_kerja);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nip', 'ilike', "%{$search}%")
                    ->orWhere('jabatan', 'ilike', "%{$search}%")
                    ->orWhereHas('pengguna', function ($userQuery) use ($search) {
                        $userQuery->where('nama_lengkap', 'ilike', "%{$search}%")
                            ->orWhere('username', 'ilike', "%{$search}%");
                    });
            });
        }

        $pembimbing = $query->latest('updated_at')
            ->orderByDesc('id')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => PembimbingResource::collection($pembimbing)->response()->getData(true),
        ]);
    }

    public function store(StorePembimbingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $pembimbing = DB::transaction(function () use ($validated, $request) {
            $pengguna = Pengguna::create([
                'username' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'nama_lengkap' => $validated['nama_lengkap'],
                'role' => 'pembimbing',
                'no_hp' => $validated['no_hp'] ?? null,
                'status' => 'aktif',
                'dibuat_oleh' => $request->user()->id,
            ]);

            $pembimbing = Pembimbing::create([
                'id_pengguna' => $pengguna->id,
                'id_unit_kerja' => $validated['id_unit_kerja'],
                'nip' => $validated['nip'],
                'jabatan' => $validated['jabatan'],
                'status_pembimbing' => $validated['status_pembimbing'] ?? 'aktif',
                'tanggal_mulai_tugas' => $validated['tanggal_mulai_tugas'] ?? null,
                'tanggal_selesai_tugas' => $validated['tanggal_selesai_tugas'] ?? null,
            ]);

            // Sinkronisasi: tandai unit kerja ini memiliki pembimbing utama
            // jika unit kerja belum memiliki pembimbing yang ditetapkan.
            if (! empty($validated['id_unit_kerja'])) {
                UnitKerja::where('id', $validated['id_unit_kerja'])
                    ->whereNull('id_pembimbing')
                    ->update(['id_pembimbing' => $pembimbing->id]);
            }

            return $pembimbing;
        });

        ActivityLogger::log('CREATE_PEMBIMBING', "Admin menambahkan pembimbing: {$pembimbing->id}");

        return response()->json([
            'success' => true,
            'message' => 'Pembimbing berhasil ditambahkan.',
            'data' => new PembimbingResource($pembimbing->load(['pengguna', 'unitKerja.pembimbing.pengguna', 'unitKerja.pembimbingByUnit.pengguna'])),
        ], 201);
    }

    public function show(Pembimbing $pembimbing): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new PembimbingResource($pembimbing->load(['pengguna', 'unitKerja.pembimbing.pengguna', 'unitKerja.pembimbingByUnit.pengguna'])),
        ]);
    }

    public function update(UpdatePembimbingRequest $request, Pembimbing $pembimbing): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $pembimbing) {
            $penggunaData = [];
            if (isset($validated['username'])) {
                $penggunaData['username'] = $validated['username'];
            }
            if (! empty($validated['password'])) {
                $penggunaData['password'] = Hash::make($validated['password']);
            }
            if (isset($validated['nama_lengkap'])) {
                $penggunaData['nama_lengkap'] = $validated['nama_lengkap'];
            }
            if (array_key_exists('no_hp', $validated)) {
                $penggunaData['no_hp'] = $validated['no_hp'];
            }

            if (! empty($penggunaData)) {
                $pembimbing->pengguna()->update($penggunaData);
            }

            $pembimbingData = array_diff_key($validated, array_flip(['username', 'password', 'nama_lengkap', 'no_hp']));
            if (! empty($pembimbingData)) {
                $oldUnitKerjaId = $pembimbing->id_unit_kerja;
                $pembimbing->update($pembimbingData);

                // Sinkronisasi unit_kerja.id_pembimbing saat unit kerja berubah.
                if (array_key_exists('id_unit_kerja', $pembimbingData)) {
                    $newUnitKerjaId = $pembimbing->id_unit_kerja;

                    // Lepaskan dari unit lama jika pembimbing ini adalah pembimbing utamanya.
                    if ($oldUnitKerjaId && $oldUnitKerjaId !== $newUnitKerjaId) {
                        UnitKerja::where('id', $oldUnitKerjaId)
                            ->where('id_pembimbing', $pembimbing->id)
                            ->update(['id_pembimbing' => null]);
                    }

                    // Daftarkan ke unit baru jika belum ada pembimbing utama.
                    if ($newUnitKerjaId) {
                        UnitKerja::where('id', $newUnitKerjaId)
                            ->whereNull('id_pembimbing')
                            ->update(['id_pembimbing' => $pembimbing->id]);
                    }
                }
            }
        });

        ActivityLogger::log('UPDATE_PEMBIMBING', "Admin memperbarui data pembimbing: {$pembimbing->id}");

        return response()->json([
            'success' => true,
            'message' => 'Data pembimbing berhasil diperbarui.',
            'data' => new PembimbingResource($pembimbing->fresh(['pengguna', 'unitKerja.pembimbing.pengguna', 'unitKerja.pembimbingByUnit.pengguna'])),
        ]);
    }

    public function destroy(Pembimbing $pembimbing): JsonResponse
    {
        $id = $pembimbing->id;
        $pengguna = $pembimbing->pengguna;

        DB::transaction(function () use ($pembimbing, $pengguna) {
            $pembimbing->delete();
            $pengguna?->delete();
        });

        ActivityLogger::log('DELETE_PEMBIMBING', "Admin menghapus pembimbing: {$id}");

        return response()->json([
            'success' => true,
            'message' => 'Data pembimbing berhasil dihapus.',
        ]);
    }
}
