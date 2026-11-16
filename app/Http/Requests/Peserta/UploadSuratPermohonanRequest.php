<?php

namespace App\Http\Requests\Peserta;

use Illuminate\Foundation\Http\FormRequest;

class UploadSuratPermohonanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPesertaMagang() ?? false;
    }

    public function rules(): array
    {
        return [
            'file_surat_permohonan' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
