<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitKerjaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'kode_unit' => ['required', 'string', 'max:50', 'unique:unit_kerja,kode_unit'],
            'nama_unit' => ['required', 'string', 'max:255'],
            'tipe' => ['required', 'in:bidang,seksi,sub_bagian,unit'],
            'id_induk' => ['nullable', 'exists:unit_kerja,id'],
            'id_pembimbing' => ['nullable', 'exists:pembimbing,id', 'unique:unit_kerja,id_pembimbing'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }
}
