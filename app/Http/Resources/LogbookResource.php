<?php

namespace App\Http\Resources;

use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogbookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fileService = app(FileUploadService::class);
        $parafPembimbingUrl = $fileService->url($this->paraf_pembimbing);

        return [
            'id' => $this->id,
            'id_peserta_magang' => $this->id_peserta_magang,
            'id_absensi' => $this->id_absensi,
            'tanggal' => $this->tanggal?->toDateString(),
            'jam_mulai' => $this->jam_mulai,
            'jam_selesai' => $this->jam_selesai,
            'kegiatan' => $this->kegiatan,
            'foto_kegiatan_url' => $fileService->url($this->foto_kegiatan),
            'status_verifikasi' => $this->status_verifikasi,
            'catatan_pembimbing' => $this->catatan_pembimbing,
            'is_draft' => $this->is_draft,
            'paraf_pembimbing' => $parafPembimbingUrl,
            'paraf_pembimbing_url' => $parafPembimbingUrl,
            'diverifikasi_oleh' => $this->diverifikasi_oleh,
            'diverifikasi_pada' => $this->diverifikasi_pada?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'peserta_magang' => new PesertaMagangResource($this->whenLoaded('pesertaMagang')),
            'verifikator' => new PembimbingResource($this->whenLoaded('verifikator')),
        ];
    }
}
