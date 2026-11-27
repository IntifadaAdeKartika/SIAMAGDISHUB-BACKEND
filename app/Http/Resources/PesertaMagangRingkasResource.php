<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Data peserta ringkas untuk response absensi.
 * Dokumen surat tidak disertakan agar payload daftar absensi tetap kecil.
 */
class PesertaMagangRingkasResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $unitKerja = $this->relationLoaded('unitKerja')
            ? $this->unitKerja
            : ($this->relationLoaded('unit_kerja') ? $this->unit_kerja : null);
        $pembimbing = $unitKerja?->pembimbingEfektif();

        return [
            'id' => $this->id,
            'id_peserta_magang' => $this->id,
            'id_pengguna' => $this->id_pengguna,
            'id_unit_kerja' => $this->id_unit_kerja,
            'nama_lengkap' => $this->relationLoaded('pengguna') ? $this->pengguna?->nama_lengkap : null,
            'jenis_peserta' => $this->jenis_peserta,
            'prodi_jurusan' => $this->prodi_jurusan,
            'asal_sekolah_kampus' => $this->asal_sekolah_kampus,
            'kelas_semester' => $this->kelas_semester,
            'pembimbing_akademik' => $this->pembimbing_akademik,
            'nip_pembimbing_akademik' => $this->nip_pembimbing_akademik,
            'no_hp_pembimbing_akademik' => $this->no_hp_pembimbing_akademik,
            'status_peserta' => $this->status_peserta,
            'tanggal_mulai' => $this->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $this->tanggal_selesai?->toDateString(),
            'status_dokumen' => $this->status_dokumen,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'pengguna' => new PenggunaResource($this->whenLoaded('pengguna')),
            'unit_kerja' => $unitKerja ? new UnitKerjaResource($unitKerja) : null,
            'pembimbing' => $pembimbing ? new PembimbingResource($pembimbing) : null,
        ];
    }
}
