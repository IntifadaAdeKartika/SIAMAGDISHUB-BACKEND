<?php

namespace App\Http\Resources;

use App\Models\PesertaMagang;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PembimbingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_pengguna' => $this->id_pengguna,
            'id_unit_kerja' => $this->id_unit_kerja,
            'nip' => $this->nip,
            'jabatan' => $this->jabatan,
            'status_pembimbing' => $this->status_pembimbing,
            'tanggal_mulai_tugas' => $this->tanggal_mulai_tugas?->toDateString(),
            'tanggal_selesai_tugas' => $this->tanggal_selesai_tugas?->toDateString(),
            'total_bimbingan' => $this->when(
                array_key_exists('total_bimbingan', $this->resource->getAttributes()),
                (int) $this->resource->getAttribute('total_bimbingan'),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'pengguna' => new PenggunaResource($this->whenLoaded('pengguna')),
            'unit_kerja' => new UnitKerjaResource($this->whenLoaded('unitKerja')),
            'daftar_peserta_bimbingan' => $this->whenLoaded(
                'pesertaBimbingan',
                fn () => $this->pesertaBimbingan->map(fn (PesertaMagang $peserta): array => [
                    'id' => $peserta->id,
                    'pengguna' => [
                        'nama_lengkap' => $peserta->pengguna?->nama_lengkap,
                    ],
                    'asal_sekolah_kampus' => $peserta->asal_sekolah_kampus,
                ]),
            ),
        ];
    }
}
