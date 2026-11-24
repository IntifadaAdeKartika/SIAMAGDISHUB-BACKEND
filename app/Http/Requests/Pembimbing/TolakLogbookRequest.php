<?php

namespace App\Http\Requests\Pembimbing;

use Illuminate\Foundation\Http\FormRequest;

class TolakLogbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPembimbing() ?? false;
    }

    public function rules(): array
    {
        return [
            'catatan_pembimbing' => ['required', 'string', 'max:5000'],
            'paraf_pembimbing' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'paraf_digital' => $this->hasFile('paraf_digital')
                ? ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048']
                : ['nullable', 'string'],
        ];
    }
}
