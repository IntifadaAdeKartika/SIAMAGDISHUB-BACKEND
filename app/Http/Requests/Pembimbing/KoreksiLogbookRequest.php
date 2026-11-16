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
            'catatan_pembimbing' => ['nullable', 'required_if:status_verifikasi,rejected', 'string'],
            'status_verifikasi' => ['nullable', 'in:pending,approved,rejected'],
            'paraf_pembimbing' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'paraf_digital' => $this->hasFile('paraf_digital')
                ? ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048']
                : ['nullable', 'string'],
        ];
    }
}
