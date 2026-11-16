<?php

namespace App\Http\Requests\Peserta;

use Illuminate\Foundation\Http\FormRequest;

class StorePengajuanIzinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPesertaMagang() ?? false;
    }

    public function rules(): array
    {
        return [
            'jenis' => ['required', 'in:izin,sakit'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan' => ['required', 'string'],
            'file_surat' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
