<?php

namespace App\Http\Resources;

use App\Services\AbsensiMediaService;
use App\Services\AbsensiRevisionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AbsensiListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = app(AbsensiMediaService::class);
        $revision = app(AbsensiRevisionService::class);

        return [
            'id' => $this->id,
            'id_peserta_magang' => $this->id_peserta_magang,
            'peserta_magang_id' => $this->id_peserta_magang,
            'id_unit_kerja' => $this->id_unit_kerja,
            'tanggal_jam' => $this->tanggal_jam?->toISOString(),
            'foto_selfie_url' => $media->url($this->foto_selfie, (int) $this->id, 'selfie'),
            'lokasi' => $this->lokasi,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'status_absensi' => $this->status,
            'keterangan' => $this->keterangan,
            'paraf_pembimbing_url' => $media->url($this->paraf_pembimbing, (int) $this->id, 'paraf'),
            'diverifikasi_oleh' => $this->diverifikasi_oleh,
            'diverifikasi_pada' => $this->diverifikasi_pada?->toISOString(),
            'status_verifikasi' => $this->status_verifikasi,
            'alasan_penolakan' => $this->alasan_penolakan,
            'jumlah_resubmit' => (int) ($this->jumlah_resubmit ?? 0),
            'riwayat_penolakan' => $this->riwayat_penolakan ?? [],
            'catatan_pembimbing' => $this->catatan_pembimbing,
            'revisi_diminta' => (bool) $this->revisi_diminta,
            'revisi_diminta_pada' => $this->revisi_diminta_pada?->toISOString(),
            'revisi_dikirim_pada' => $this->revisi_dikirim_pada?->toISOString(),
            'riwayat_revisi' => $revision->serialisasiRiwayat(
                $this->riwayat_revisi ?? [],
                (int) $this->id,
                false
            ),
            'status_revisi' => $this->statusRevisi(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'peserta_magang' => new PesertaMagangRingkasResource($this->whenLoaded('pesertaMagang')),
            'unit_kerja' => new UnitKerjaResource($this->whenLoaded('unitKerja')),
            'verifikator' => new PembimbingResource($this->whenLoaded('verifikator')),
        ];
    }
}
