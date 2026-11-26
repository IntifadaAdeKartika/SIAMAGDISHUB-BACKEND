<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotifikasiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_pengguna' => $this->id_pengguna,
            'judul' => $this->judul,
            'pesan' => $this->pesan,
            'tipe' => $this->tipe,
            'link' => $this->link,
            'id_absensi' => $this->id_absensi,
            'sudah_dibaca' => $this->sudah_dibaca,
            'dibuat_pada' => $this->created_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
