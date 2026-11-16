<?php

namespace App\Http\Resources;

use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PesertaMagangResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fileService = app(FileUploadService::class);
        $unitKerja = $this->relationLoaded('unitKerja') ? $this->unitKerja : ($this->relationLoaded('unit_kerja') ? $this->unit_kerja : null);
        $pembimbing = $unitKerja?->pembimbing ?? $unitKerja?->pembimbingUtama;
        $absensiHariIni = $this->relationLoaded('absensiList') ? $this->absensiList : collect();
        $logbookHariIni = $this->relationLoaded('logbookList') ? $this->logbookList : collect();
        $absensiTerakhirHariIni = $absensiHariIni->first();
        $logbookTerakhirHariIni = $logbookHariIni->first();
        $statusPresensiHariIni = null;
        $statusPresensi = null;
        $statusLogbookHariIni = null;

        if ($this->relationLoaded('absensiList')) {
            $statusPresensi = $absensiHariIni->contains('status', 'masuk')
                ? 'Masuk'
                : 'Belum Absen';
            $statusPresensiHariIni = $absensiHariIni->contains('status', 'keluar')
                ? 'Pulang'
                : ($absensiHariIni->contains('status', 'masuk') ? 'Masuk' : 'Belum Absen');
        }

        if ($this->relationLoaded('logbookList')) {
            $statusLogbookHariIni = match ($logbookTerakhirHariIni?->status_verifikasi) {
                null => 'Belum Diisi',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                default => 'Pending',
            };
        }

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
            'file_surat_permohonan' => $this->file_surat_permohonan,
            'file_surat_balasan' => $this->file_surat_balasan,
            'has_surat_permohonan' => ! empty($this->file_surat_permohonan),
            'has_surat_balasan' => ! empty($this->file_surat_balasan),
            'url_surat_permohonan' => $fileService->url($this->file_surat_permohonan),
            'url_surat_balasan' => $fileService->url($this->file_surat_balasan),
            'status_dokumen' => $this->status_dokumen,
            'absensi_hari_ini' => AbsensiResource::collection($this->whenLoaded('absensiList')),
            'logbook_hari_ini' => LogbookResource::collection($this->whenLoaded('logbookList')),
            'absensi_terbaru' => $this->relationLoaded('absensiList') && $absensiTerakhirHariIni
                ? new AbsensiResource($absensiTerakhirHariIni)
                : null,
            'logbook_terakhir' => $this->relationLoaded('logbookList') && $logbookTerakhirHariIni
                ? new LogbookResource($logbookTerakhirHariIni)
                : null,
            'status_presensi' => $statusPresensi,
            'status_presensi_hari_ini' => $statusPresensiHariIni,
            'status_verifikasi_presensi_hari_ini' => $absensiTerakhirHariIni?->status_verifikasi,
            'status_logbook_hari_ini' => $statusLogbookHariIni,
            'status_logbook_terakhir' => $logbookTerakhirHariIni?->status_verifikasi,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'pengguna' => new PenggunaResource($this->whenLoaded('pengguna')),
            'unit_kerja' => $unitKerja ? new UnitKerjaResource($unitKerja) : null,
            'pembimbing' => $pembimbing ? new PembimbingResource($pembimbing) : null,
        ];
    }
}
