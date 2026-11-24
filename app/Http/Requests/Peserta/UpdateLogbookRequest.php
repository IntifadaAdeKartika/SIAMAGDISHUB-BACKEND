<?php

namespace App\Http\Requests\Peserta;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLogbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPesertaMagang() ?? false;
    }

    public function rules(): array
    {
        return [
            'id_absensi' => ['nullable', 'exists:absensi,id'],
            'tanggal' => ['sometimes', 'date'],
            'jam_mulai' => ['sometimes', 'date_format:H:i,H:i:s'],
            'jam_selesai' => ['sometimes', 'date_format:H:i,H:i:s'],
            'kegiatan' => ['sometimes', 'string'],
            'foto_kegiatan' => ['nullable'],
        ];
    }
}
