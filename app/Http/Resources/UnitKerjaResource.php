<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitKerjaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pembimbing = $this->pembimbingEfektif();

        return [
            'id' => $this->id,
            'id_induk' => $this->id_induk,
            'id_pembimbing' => $this->id_pembimbing,
            'kode_unit' => $this->kode_unit,
            'nama_unit' => $this->nama_unit,
            'tipe' => $this->tipe,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'induk' => new UnitKerjaResource($this->whenLoaded('induk')),
            'pembimbing' => $pembimbing ? new PembimbingResource($pembimbing) : null,
            'pembimbing_utama' => $pembimbing ? new PembimbingResource($pembimbing) : null,
        ];
    }
}
