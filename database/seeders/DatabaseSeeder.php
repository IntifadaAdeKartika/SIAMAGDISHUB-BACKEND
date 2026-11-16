<?php

namespace Database\Seeders;

use App\Models\Pembimbing;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Buat / Update Pengguna Admin
        $admin = Pengguna::updateOrCreate(
            ['username' => 'admin'],
            [
                'nama_lengkap' => 'Administrator Dishub',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'no_hp' => '081234567890',
                'status' => 'aktif',
            ]
        );

        // 2. Buat Unit Kerja
        $unitInduk = UnitKerja::firstOrCreate(
            ['kode_unit' => 'BID-LLJ'],
            [
                'nama_unit' => 'Bidang Lalu Lintas Jalan',
                'tipe' => 'bidang',
                'status' => 'aktif',
            ]
        );

        $unitKerja = UnitKerja::firstOrCreate(
            ['kode_unit' => 'SEK-REK'],
            [
                'nama_unit' => 'Seksi Rekayasa Lalu Lintas',
                'tipe' => 'seksi',
                'id_induk' => $unitInduk->id,
                'status' => 'aktif',
            ]
        );

        // 3. Buat / Update Pengguna Pembimbing Lapangan
        $penggunaPembimbing = Pengguna::updateOrCreate(
            ['username' => 'pembimbing'],
            [
                'nama_lengkap' => 'Budi Santoso, S.T., M.Eng.',
                'password' => Hash::make('pembimbing123'),
                'role' => 'pembimbing',
                'no_hp' => '081298765432',
                'status' => 'aktif',
                'dibuat_oleh' => $admin->id,
            ]
        );

        $pembimbing = Pembimbing::updateOrCreate(
            ['nip' => '198501012010011001'],
            [
                'id_pengguna' => $penggunaPembimbing->id,
                'id_unit_kerja' => $unitKerja->id,
                'jabatan' => 'Kepala Seksi Rekayasa Lalu Lintas',
                'status_pembimbing' => 'aktif',
                'tanggal_mulai_tugas' => now()->subMonths(6)->toDateString(),
            ]
        );

        // Hubungkan pembimbing utama ke unit kerja
        $unitKerja->update(['id_pembimbing' => $pembimbing->id]);

        // 4. Buat / Update Pengguna Peserta Magang
        $penggunaPeserta = Pengguna::updateOrCreate(
            ['username' => 'peserta'],
            [
                'nama_lengkap' => 'Ahmad Fauzi',
                'password' => Hash::make('peserta123'),
                'role' => 'peserta_magang',
                'no_hp' => '085712345678',
                'status' => 'aktif',
                'dibuat_oleh' => $admin->id,
            ]
        );

        PesertaMagang::updateOrCreate(
            ['id_pengguna' => $penggunaPeserta->id],
            [
                'id_unit_kerja' => $unitKerja->id,
                'jenis_peserta' => 'mahasiswa',
                'prodi_jurusan' => 'Teknik Informatika',
                'asal_sekolah_kampus' => 'Universitas Indonesia',
                'kelas_semester' => 'Semester 7',
                'pembimbing_akademik' => 'Dr. Eng. Ir. Hendra Gunawan',
                'nip_pembimbing_akademik' => '197505052000031001',
                'no_hp_pembimbing_akademik' => '08111222333',
                'status_peserta' => 'aktif',
                'tanggal_mulai' => now()->subDays(10)->toDateString(),
                'tanggal_selesai' => now()->addMonths(2)->toDateString(),
                'status_dokumen' => 'pending',
            ]
        );
    }
}
