<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePesertaMagangRequest extends FormRequest
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
            'jenis_peserta' => ['required', 'in:siswa,mahasiswa'],
            'prodi_jurusan' => ['required', 'string', 'max:255'],
            'asal_sekolah_kampus' => ['required', 'string', 'max:255'],
            'kelas_semester' => ['required', 'string', 'max:50'],
            'pembimbing_akademik' => ['nullable', 'string', 'max:255'],
            'nip_pembimbing_akademik' => ['nullable', 'string', 'max:50'],
            'no_hp_pembimbing_akademik' => ['nullable', 'string', 'max:20'],
            'status_peserta' => ['nullable', 'in:aktif,selesai,keluar,pending'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
        ];
    }
}
