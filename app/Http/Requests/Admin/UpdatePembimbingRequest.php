<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePembimbingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $pembimbing = $this->route('pembimbing');
        $idPengguna = is_object($pembimbing) ? $pembimbing->id_pengguna : null;
        $idPembimbing = is_object($pembimbing) ? $pembimbing->id : null;

        return [
            'username' => ['sometimes', 'string', 'unique:pengguna,username,'.$idPengguna],
            'password' => ['nullable', 'string', 'min:6'],
            'nama_lengkap' => ['sometimes', 'string', 'max:255'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'id_unit_kerja' => ['sometimes', 'exists:unit_kerja,id'],
            'nip' => ['sometimes', 'string', 'max:50', 'unique:pembimbing,nip,'.$idPembimbing],
            'jabatan' => ['sometimes', 'string', 'max:150'],
            'status_pembimbing' => ['nullable', 'string', 'max:50'],
            'tanggal_mulai_tugas' => ['nullable', 'date'],
            'tanggal_selesai_tugas' => ['nullable', 'date', 'after_or_equal:tanggal_mulai_tugas'],
        ];
    }
}
