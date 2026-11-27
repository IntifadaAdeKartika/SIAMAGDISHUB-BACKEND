<?php

namespace App\Http\Requests\Pembimbing;

use Illuminate\Foundation\Http\FormRequest;

class MintaRevisiAbsensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPembimbing() ?? false;
    }

    public function rules(): array
    {
        return [
            'bagian' => ['required', 'string', 'in:check-in,check-out,both'],
            'catatan' => ['required_without:catatan_pembimbing', 'nullable', 'string', 'max:1000'],
            'catatan_pembimbing' => ['required_without:catatan', 'nullable', 'string', 'max:1000'],
        ];
    }
}
