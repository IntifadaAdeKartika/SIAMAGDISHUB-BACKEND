<?php

namespace App\Http\Requests\Admin;

use App\Models\UnitKerja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitKerjaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    // Tambahan: Ubah input jadi huruf kecil sebelum divalidasi
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
        $unitKerja = $this->route('unit_kerja');
        $unitId = $unitKerja instanceof UnitKerja ? $unitKerja->id : ($unitKerja ?? $this->route('id'));

        return [
            'kode_unit' => ['sometimes', 'string', 'max:50', Rule::unique('unit_kerja', 'kode_unit')->ignore($unitId)],
            'nama_unit' => ['sometimes', 'string', 'max:255'],
            'tipe' => ['sometimes', 'in:sekretariat,sub_bagian,bidang,seksi,upt'],
            'id_induk' => ['nullable', 'exists:unit_kerja,id', 'different:'.$unitId],
            'id_pembimbing' => ['nullable', 'exists:pembimbing,id'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }
}