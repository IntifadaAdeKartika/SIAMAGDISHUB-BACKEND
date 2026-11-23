<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitKerjaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation()
    {
        if ($this->has('tipe')) {
            $this->merge([
                'tipe' => strtolower($this->tipe)
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'kode_unit' => ['required', 'string', 'max:50', 'unique:unit_kerja,kode_unit'],
            'nama_unit' => ['required', 'string', 'max:255'],
            // PERBAIKAN: Tambahkan sekretariat dan upt
            'tipe' => ['required', 'in:sekretariat,sub_bagian,bidang,seksi,upt'],
            'id_induk' => ['nullable', 'exists:unit_kerja,id'],
            'id_pembimbing' => ['nullable', 'exists:pembimbing,id'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }
}