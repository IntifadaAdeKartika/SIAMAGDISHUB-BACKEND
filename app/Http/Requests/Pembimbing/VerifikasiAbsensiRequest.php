<?php

namespace App\Http\Requests\Pembimbing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class VerifikasiAbsensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPembimbing() ?? false;
    }

    public function rules(): array
    {
        return [
            'paraf_digital' => [
                'required',
                $this->hasFile('paraf_digital')
                    ? File::image()->max(5120)
                    : 'string',
            ],
            'status_verifikasi' => ['nullable', 'in:approved,rejected'],
            'alasan_penolakan' => ['required_if:status_verifikasi,rejected', 'string', 'max:500'],
        ];
    }
}
