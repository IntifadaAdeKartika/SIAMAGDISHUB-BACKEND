<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PenggunaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nama_lengkap' => $this->nama_lengkap,
            'role' => $this->role,
            'no_hp' => $this->no_hp,
            'status' => $this->status,
            'terakhir_login' => $this->terakhir_login?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'pembimbing' => new PembimbingResource($this->whenLoaded('pembimbing')),
            'peserta_magang' => new PesertaMagangResource($this->whenLoaded('pesertaMagang')),
            'pesertaMagang' => new PesertaMagangResource($this->whenLoaded('pesertaMagang')),
            'unit_kerja' => $this->when(
                $this->relationLoaded('pesertaMagang') && $this->pesertaMagang?->relationLoaded('unitKerja'),
                fn () => $this->pesertaMagang?->unitKerja ? new UnitKerjaResource($this->pesertaMagang->unitKerja) : null,
                $this->when(
                    $this->relationLoaded('pembimbing') && $this->pembimbing?->relationLoaded('unitKerja'),
                    fn () => $this->pembimbing?->unitKerja ? new UnitKerjaResource($this->pembimbing->unitKerja) : null
                )
            ),
        ];
    }
}
