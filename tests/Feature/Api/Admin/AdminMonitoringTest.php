<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Absensi;
use App\Models\LogAktivitas;
use App\Models\Logbook;
use App\Models\Pembimbing;
use App\Models\PengajuanIzin;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_paginated_monitoring_records_with_participant_names(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $admin = $this->createPengguna('admin', 'Admin');
        $peserta = $this->createPesertaMagang();
        $parafPath = 'logbook/paraf/admin-signature.png';

        Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal_jam' => '2026-11-10 08:00:00',
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
        ]);
        Logbook::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal' => '2026-11-10',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:00:00',
            'kegiatan' => 'Pemeriksaan kendaraan',
            'catatan_pembimbing' => 'Kegiatan sesuai.',
            'paraf_pembimbing' => $parafPath,
        ]);
        PengajuanIzin::create([
            'id_peserta_magang' => $peserta->id,
            'jenis' => 'izin',
            'tanggal_mulai' => '2026-11-11',
            'tanggal_selesai' => '2026-11-11',
            'keterangan' => 'Keperluan keluarga',
        ]);
        $logAktivitas = LogAktivitas::create([
            'id_pengguna' => $peserta->id_pengguna,
            'aksi' => 'LOGIN',
            'deskripsi' => 'Peserta masuk',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Admin monitoring test',
        ]);

        $this->actingAs($admin, 'sanctum');

        $this->getJson('/backend-api/admin/absensi')
            ->assertOk()
            ->assertJsonPath('data.per_page', 20)
            ->assertJsonPath('data.data.0.foto_selfie_url', Storage::disk('public')->url('absensi/selfie.jpg'))
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Contoh');
        $this->getJson('/backend-api/admin/logbook')
            ->assertOk()
            ->assertJsonPath('data.per_page', 15)
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Contoh')
            ->assertJsonPath('data.data.0.catatan_pembimbing', 'Kegiatan sesuai.')
            ->assertJsonPath('data.data.0.paraf_pembimbing_url', Storage::disk('public')->url($parafPath));
        $this->getJson('/backend-api/admin/pengajuan-izin')
            ->assertOk()
            ->assertJsonPath('data.per_page', 15)
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Contoh');
        $this->getJson('/backend-api/admin/log-aktivitas')
            ->assertOk()
            ->assertJsonPath('data.per_page', 15)
            ->assertJsonPath('data.data.0.id', $logAktivitas->id)
            ->assertJsonPath('data.data.0.aksi', 'LOGIN')
            ->assertJsonPath('data.data.0.deskripsi', 'Peserta masuk')
            ->assertJsonPath('data.data.0.ip_address', '127.0.0.1')
            ->assertJsonPath('data.data.0.user_agent', 'Admin monitoring test')
            ->assertJsonPath('data.data.0.dibuat_pada', $logAktivitas->created_at->toISOString())
            ->assertJsonPath('data.data.0.pengguna.nama_lengkap', 'Peserta Contoh')
            ->assertJsonPath('data.data.0.pengguna.role', 'peserta_magang');
    }

    public function test_admin_can_view_document_paths_and_download_documents(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $admin = $this->createPengguna('admin', 'Admin');
        $peserta = $this->createPesertaMagang([
            'file_surat_permohonan' => 'dokumen/surat-permohonan.pdf',
            'file_surat_balasan' => 'dokumen/surat-balasan.pdf',
            'status_dokumen' => 'lengkap',
        ]);

        Storage::disk('public')->put('dokumen/surat-permohonan.pdf', 'surat permohonan');
        Storage::disk('public')->put('dokumen/surat-balasan.pdf', 'surat balasan');

        $this->actingAs($admin, 'sanctum');

        $this->getJson('/backend-api/admin/dokumen')
            ->assertOk()
            ->assertJsonPath('data.data.0.status_dokumen', 'lengkap')
            ->assertJsonPath('data.data.0.file_surat_permohonan', 'dokumen/surat-permohonan.pdf')
            ->assertJsonPath('data.data.0.file_surat_balasan', 'dokumen/surat-balasan.pdf');
        $this->get('/backend-api/admin/dokumen/surat-permohonan/'.$peserta->id)
            ->assertDownload('Surat_Permohonan_Peserta_Contoh.pdf');
        $this->get('/backend-api/admin/dokumen/surat-balasan/'.$peserta->id)
            ->assertDownload('Surat_Balasan_Peserta_Contoh.pdf');
    }

    public function test_admin_document_archive_includes_incomplete_participants_and_document_fields(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $admin = $this->createPengguna('admin', 'Admin');
        $pesertaLengkap = $this->createPesertaMagang([
            'file_surat_permohonan' => 'dokumen/surat-permohonan.pdf',
            'file_surat_balasan' => 'dokumen/surat-balasan.pdf',
            'status_dokumen' => 'lengkap',
        ]);
        $pesertaBelumLengkap = $this->createPesertaMagang([], 'peserta-pending');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/backend-api/admin/dokumen?status_dokumen=lengkap')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $pesertaById = collect($response->json('data.data'))->keyBy('id');
        $dataLengkap = $pesertaById[$pesertaLengkap->id];
        $dataBelumLengkap = $pesertaById[$pesertaBelumLengkap->id];

        $this->assertSame('Peserta Contoh', $dataLengkap['pengguna']['nama_lengkap']);
        $this->assertSame('dokumen/surat-permohonan.pdf', $dataLengkap['file_surat_permohonan']);
        $this->assertSame('dokumen/surat-balasan.pdf', $dataLengkap['file_surat_balasan']);
        $this->assertSame(Storage::disk('public')->url('dokumen/surat-permohonan.pdf'), $dataLengkap['url_surat_permohonan']);
        $this->assertSame(Storage::disk('public')->url('dokumen/surat-balasan.pdf'), $dataLengkap['url_surat_balasan']);
        $this->assertSame('lengkap', $dataLengkap['status_dokumen']);
        $this->assertSame('pending', $dataBelumLengkap['status_dokumen']);
        $this->assertNull($dataBelumLengkap['file_surat_permohonan']);
        $this->assertNull($dataBelumLengkap['file_surat_balasan']);
    }

    public function test_admin_monitoring_includes_participant_and_verifying_supervisor_names(): void
    {
        $admin = $this->createPengguna('admin', 'Admin');
        $unitKerja = UnitKerja::create([
            'kode_unit' => 'UNIT-ADMIN',
            'nama_unit' => 'Unit Admin',
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
        $pembimbingPengguna = $this->createPengguna('pembimbing', 'Pembimbing');
        $pembimbing = Pembimbing::create([
            'id_pengguna' => $pembimbingPengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'nip' => 'NIP-ADMIN',
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);
        $unitKerja->update(['id_pembimbing' => $pembimbing->id]);
        $peserta = $this->createPesertaMagang([
            'id_unit_kerja' => $unitKerja->id,
        ]);
        Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => '2026-11-10 08:00:00',
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'diverifikasi_oleh' => $pembimbing->id,
        ]);
        Logbook::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal' => '2026-11-10',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:00:00',
            'kegiatan' => 'Pemeriksaan kendaraan',
            'diverifikasi_oleh' => $pembimbing->id,
        ]);
        PengajuanIzin::create([
            'id_peserta_magang' => $peserta->id,
            'jenis' => 'izin',
            'tanggal_mulai' => '2026-11-11',
            'tanggal_selesai' => '2026-11-11',
            'keterangan' => 'Keperluan keluarga',
            'diverifikasi_oleh' => $pembimbing->id,
        ]);

        $this->actingAs($admin, 'sanctum');

        // Absensi: peserta_magang.unit_kerja dan peserta_magang.pembimbing harus terisi
        $this->getJson('/backend-api/admin/absensi')
            ->assertOk()
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Contoh')
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.nama_unit', 'Unit Admin')
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.pembimbing.pengguna.nama_lengkap', 'Pembimbing')
            ->assertJsonPath('data.data.0.peserta_magang.pembimbing.pengguna.nama_lengkap', 'Pembimbing')
            ->assertJsonPath('data.data.0.unit_kerja.pembimbing.pengguna.nama_lengkap', 'Pembimbing')
            ->assertJsonPath('data.data.0.verifikator.pengguna.nama_lengkap', 'Pembimbing');

        // Logbook: peserta_magang.unit_kerja dan peserta_magang.pembimbing harus terisi
        $this->getJson('/backend-api/admin/logbook')
            ->assertOk()
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Contoh')
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.nama_unit', 'Unit Admin')
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.pembimbing.pengguna.nama_lengkap', 'Pembimbing')
            ->assertJsonPath('data.data.0.peserta_magang.pembimbing.pengguna.nama_lengkap', 'Pembimbing')
            ->assertJsonPath('data.data.0.verifikator.pengguna.nama_lengkap', 'Pembimbing');

        // Pengajuan Izin: menggunakan PengajuanIzinResource sehingga peserta_magang.pembimbing tersedia
        $this->getJson('/backend-api/admin/pengajuan-izin')
            ->assertOk()
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Contoh')
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.nama_unit', 'Unit Admin')
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.pembimbing.pengguna.nama_lengkap', 'Pembimbing')
            ->assertJsonPath('data.data.0.peserta_magang.pembimbing.pengguna.nama_lengkap', 'Pembimbing')
            ->assertJsonPath('data.data.0.verifikator.pengguna.nama_lengkap', 'Pembimbing');
    }

    public function test_monitoring_routes_reject_unauthenticated_and_non_admin_users(): void
    {
        $this->getJson('/backend-api/admin/absensi')->assertUnauthorized();

        $peserta = $this->createPengguna('peserta_magang', 'Peserta');

        $this->actingAs($peserta, 'sanctum')
            ->getJson('/backend-api/admin/absensi')
            ->assertForbidden();
    }

    private function createPengguna(string $role, string $username): Pengguna
    {
        return Pengguna::create([
            'username' => $username,
            'nama_lengkap' => $role === 'peserta_magang' ? 'Peserta Contoh' : $username,
            'password' => 'password',
            'role' => $role,
            'status' => 'aktif',
        ]);
    }

    private function createPesertaMagang(array $attributes = [], string $username = 'peserta'): PesertaMagang
    {
        $pengguna = $this->createPengguna('peserta_magang', $username);

        return PesertaMagang::create(array_merge([
            'id_pengguna' => $pengguna->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK Contoh',
            'kelas_semester' => 'XII',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ], $attributes));
    }
}
