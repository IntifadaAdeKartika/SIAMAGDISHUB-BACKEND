<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePembimbingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'unique:pengguna,username'],
            'password' => ['required', 'string', 'min:6'],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'id_unit_kerja' => ['required', 'exists:unit_kerja,id'],
            'nip' => ['required', 'string', 'max:50', 'unique:pembimbing,nip'],
            'jabatan' => ['required', 'string', 'max:150'],
            'status_pembimbing' => ['nullable', 'string', 'max:50'],
            'tanggal_mulai_tugas' => ['nullable', 'date'],
            'tanggal_selesai_tugas' => ['nullable', 'date', 'after_or_equal:tanggal_mulai_tugas'],
        ];
    }
}
