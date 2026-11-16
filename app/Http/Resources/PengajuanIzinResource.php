<?php

namespace App\Http\Resources;

use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PengajuanIzinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fileService = app(FileUploadService::class);

        return [
            'id' => $this->id,
            'id_peserta_magang' => $this->id_peserta_magang,
            'jenis' => $this->jenis,
            'tanggal_mulai' => $this->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $this->tanggal_selesai?->toDateString(),
            'keterangan' => $this->keterangan,
            'file_surat_url' => $fileService->url($this->file_surat),
            'status' => $this->status,
            'diverifikasi_oleh' => $this->diverifikasi_oleh,
            'diverifikasi_pada' => $this->diverifikasi_pada?->toISOString(),
            'catatan_pembimbing' => $this->catatan_pembimbing,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'peserta_magang' => new PesertaMagangResource($this->whenLoaded('pesertaMagang')),
            'verifikator' => new PembimbingResource($this->whenLoaded('verifikator')),
        ];
    }
}
