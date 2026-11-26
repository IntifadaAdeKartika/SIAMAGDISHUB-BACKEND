<?php

namespace App\Http\Requests\Pembimbing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class VerifikasiAbsensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPembimbing() ?? false;
    }

    public function rules(): array
    {
        $status = $this->input('status_verifikasi', 'approved');

        $parafRules = $this->hasFile('paraf_digital')
            ? [File::image()->max(5120)]
            : ['string'];

        return [
            // Paraf hanya wajib saat menyetujui; penolakan cukup alasan.
            'paraf_digital' => array_merge([
                Rule::requiredIf(fn (): bool => $status !== 'rejected'),
            ], $parafRules),
            'status_verifikasi' => ['nullable', 'in:approved,rejected'],
            'alasan_penolakan' => [
                Rule::requiredIf(fn (): bool => $status === 'rejected'),
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'paraf_digital.required' => 'Paraf pembimbing wajib diunggah untuk menyetujui presensi.',
            'alasan_penolakan.required' => 'Keterangan penolakan wajib diisi.',
            'alasan_penolakan.max' => 'Keterangan penolakan maksimal 500 karakter.',
        ];
    }
}
