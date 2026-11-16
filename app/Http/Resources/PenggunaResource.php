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
        ];
    }
}
