<?php

namespace App\Http\Requests\Peserta;

use Illuminate\Foundation\Http\FormRequest;

class StoreLogbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPesertaMagang() ?? false;
    }

    public function rules(): array
    {
        return [
            'id_absensi' => ['nullable', 'exists:absensi,id'],
            'tanggal' => ['required', 'date'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i', 'after:jam_mulai'],
            'kegiatan' => ['required', 'string'],
            'foto_kegiatan' => ['nullable'], // UploadedFile or Base64
        ];
    }
}
