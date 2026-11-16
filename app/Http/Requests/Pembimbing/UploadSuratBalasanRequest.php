<?php

namespace App\Http\Requests\Pembimbing;

use Illuminate\Foundation\Http\FormRequest;

class UploadSuratBalasanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPembimbing() ?? false;
    }

    public function rules(): array
    {
        return [
            'file_surat_balasan' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
