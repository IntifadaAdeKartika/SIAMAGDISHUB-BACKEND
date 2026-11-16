<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogAktivitasResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_pengguna' => $this->id_pengguna,
            'aksi' => $this->aksi,
            'deskripsi' => $this->deskripsi,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'dibuat_pada' => $this->created_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'pengguna' => new PenggunaResource($this->whenLoaded('pengguna')),
        ];
    }
}
