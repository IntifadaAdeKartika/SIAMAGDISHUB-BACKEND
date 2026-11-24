<?php

namespace App\Http\Requests\Pembimbing;

use Illuminate\Foundation\Http\FormRequest;

class KoreksiLogbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPembimbing() ?? false;
    }

    public function rules(): array
    {
        return [
            'aksi' => ['nullable', 'in:approve,setujui,revisi,minta_revisi,tolak,rejected'],
            'final' => ['nullable', 'boolean'],
            'catatan_pembimbing' => [
                'nullable',
                'string',
                'max:5000',
                'required_if:aksi,revisi',
                'required_if:aksi,minta_revisi',
                'required_if:aksi,tolak',
                'required_if:aksi,rejected',
                'required_if:final,true',
                'required_if:status_verifikasi,rejected',
                'required_if:status_verifikasi,pending',
            ],
            'status_verifikasi' => ['nullable', 'in:pending,approved,rejected'],
            'paraf_pembimbing' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'paraf_digital' => $this->hasFile('paraf_digital')
                ? ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048']
                : ['nullable', 'string'],
        ];
    }
}
