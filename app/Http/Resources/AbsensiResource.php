<?php

namespace App\Http\Resources;

use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AbsensiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fileService = app(FileUploadService::class);

        return [
            'id' => $this->id,
            'id_peserta_magang' => $this->id_peserta_magang,
            'id_unit_kerja' => $this->id_unit_kerja,
            'tanggal_jam' => $this->tanggal_jam?->toISOString(),
            'foto_selfie_url' => $fileService->url($this->foto_selfie),
            'lokasi' => $this->lokasi,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'keterangan' => $this->keterangan,
            'paraf_pembimbing_url' => $fileService->url($this->paraf_pembimbing),
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
            'riwayat_revisi' => $this->riwayat_revisi ?? [],
            'status_revisi' => $this->statusRevisi(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'peserta_magang' => new PesertaMagangResource($this->whenLoaded('pesertaMagang')),
            'unit_kerja' => new UnitKerjaResource($this->whenLoaded('unitKerja')),
            'verifikator' => new PembimbingResource($this->whenLoaded('verifikator')),
        ];
    }
}
