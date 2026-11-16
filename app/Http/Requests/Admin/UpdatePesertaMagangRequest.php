<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePesertaMagangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $peserta = $this->route('peserta') ?? $this->route('peserta_magang');
        $idPengguna = is_object($peserta) ? $peserta->id_pengguna : null;

        return [
            'username' => ['sometimes', 'string', 'unique:pengguna,username,'.$idPengguna],
            'password' => ['nullable', 'string', 'min:6'],
            'nama_lengkap' => ['sometimes', 'string', 'max:255'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'id_unit_kerja' => ['sometimes', 'exists:unit_kerja,id'],
            'jenis_peserta' => ['sometimes', 'in:siswa,mahasiswa'],
            'prodi_jurusan' => ['sometimes', 'string', 'max:255'],
            'asal_sekolah_kampus' => ['sometimes', 'string', 'max:255'],
            'kelas_semester' => ['sometimes', 'string', 'max:50'],
            'pembimbing_akademik' => ['nullable', 'string', 'max:255'],
            'nip_pembimbing_akademik' => ['nullable', 'string', 'max:50'],
            'no_hp_pembimbing_akademik' => ['nullable', 'string', 'max:20'],
            'status_peserta' => ['sometimes', 'in:aktif,selesai,keluar,pending'],
            'tanggal_mulai' => ['sometimes', 'date'],
            'tanggal_selesai' => ['sometimes', 'date', 'after_or_equal:tanggal_mulai'],
        ];
    }
}
