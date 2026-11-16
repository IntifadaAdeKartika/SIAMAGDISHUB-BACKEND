<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePesertaMagangRequest;
use App\Http\Requests\Admin\UpdatePesertaMagangRequest;
use App\Http\Resources\PesertaMagangResource;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PesertaMagangController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PesertaMagang::with([
            'pengguna',
            'unitKerja.pembimbing.pengguna',
        ]);

        if ($request->filled('id_unit_kerja')) {
            $query->where('id_unit_kerja', $request->id_unit_kerja);
        }

        if ($request->filled('status_peserta')) {
            $query->where('status_peserta', $request->status_peserta);
        }

        if ($request->filled('status_dokumen')) {
            $query->where('status_dokumen', $request->status_dokumen);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('asal_sekolah_kampus', 'ilike', "%{$search}%")
                    ->orWhere('prodi_jurusan', 'ilike', "%{$search}%")
                    ->orWhereHas('pengguna', function ($userQuery) use ($search) {
                        $userQuery->where('nama_lengkap', 'ilike', "%{$search}%")
                            ->orWhere('username', 'ilike', "%{$search}%");
                    });
            });
        }

        $peserta = $query->latest('updated_at')
            ->orderByDesc('id')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => PesertaMagangResource::collection($peserta)->response()->getData(true),
        ]);
    }

    public function store(StorePesertaMagangRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $peserta = DB::transaction(function () use ($validated, $request) {
            $pengguna = Pengguna::create([
                'username' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'nama_lengkap' => $validated['nama_lengkap'],
                'role' => 'peserta_magang',
                'no_hp' => $validated['no_hp'] ?? null,
                'status' => 'aktif',
                'dibuat_oleh' => $request->user()->id,
            ]);

            return PesertaMagang::create([
                'id_pengguna' => $pengguna->id,
                'id_unit_kerja' => $validated['id_unit_kerja'],
                'jenis_peserta' => $validated['jenis_peserta'],
                'prodi_jurusan' => $validated['prodi_jurusan'],
                'asal_sekolah_kampus' => $validated['asal_sekolah_kampus'],
                'kelas_semester' => $validated['kelas_semester'],
                'pembimbing_akademik' => $validated['pembimbing_akademik'] ?? null,
                'nip_pembimbing_akademik' => $validated['nip_pembimbing_akademik'] ?? null,
                'no_hp_pembimbing_akademik' => $validated['no_hp_pembimbing_akademik'] ?? null,
                'status_peserta' => $validated['status_peserta'] ?? 'aktif',
                'tanggal_mulai' => $validated['tanggal_mulai'],
                'tanggal_selesai' => $validated['tanggal_selesai'],
                'status_dokumen' => 'pending',
            ]);
        });

        ActivityLogger::log('CREATE_PESERTA', "Admin menambahkan peserta magang: {$peserta->id}");

        return response()->json([
            'success' => true,
            'message' => 'Peserta magang berhasil ditambahkan.',
            'data' => new PesertaMagangResource($peserta->load(['pengguna', 'unitKerja.pembimbing.pengguna'])),
        ], 201);
    }

    public function show(PesertaMagang $pesertaMagang): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new PesertaMagangResource($pesertaMagang->load(['pengguna', 'unitKerja.pembimbing.pengguna'])),
        ]);
    }

    public function update(UpdatePesertaMagangRequest $request, PesertaMagang $pesertaMagang): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $pesertaMagang) {
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
                $pesertaMagang->pengguna()->update($penggunaData);
                $pesertaMagang->touch();
            }

            $pesertaData = array_diff_key($validated, array_flip(['username', 'password', 'nama_lengkap', 'no_hp']));
            if (! empty($pesertaData)) {
                $pesertaMagang->update($pesertaData);
            }
        });

        ActivityLogger::log('UPDATE_PESERTA', "Admin memperbarui peserta magang: {$pesertaMagang->id}");

        return response()->json([
            'success' => true,
            'message' => 'Data peserta magang berhasil diperbarui.',
            'data' => new PesertaMagangResource($pesertaMagang->fresh(['pengguna', 'unitKerja.pembimbing.pengguna'])),
        ]);
    }

    public function destroy(PesertaMagang $pesertaMagang): JsonResponse
    {
        $id = $pesertaMagang->id;
        $pengguna = $pesertaMagang->pengguna;

        DB::transaction(function () use ($pesertaMagang, $pengguna) {
            $pesertaMagang->delete();
            $pengguna?->delete();
        });

        ActivityLogger::log('DELETE_PESERTA', "Admin menghapus peserta magang: {$id}");

        return response()->json([
            'success' => true,
            'message' => 'Data peserta magang berhasil dihapus.',
        ]);
    }
}
