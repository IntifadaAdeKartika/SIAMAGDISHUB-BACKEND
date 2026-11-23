<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Absensi;
use App\Models\Logbook;
use App\Models\Pembimbing;
use App\Models\PengajuanIzin;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPembimbingResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_monitoring_resolves_supervisor_from_pembimbing_unit_relation(): void
    {
        $admin = $this->createPengguna('admin', 'Admin');
        $unitKerja = $this->createUnit('UNIT-FALLBACK', 'Unit Fallback');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-fallback', 'Pembimbing Fallback');
        $peserta = $this->createPeserta($unitKerja, 'peserta-fallback');

        // Sengaja tidak mengisi unit_kerja.id_pembimbing untuk mereplikasi data
        // yang ditetapkan lewat menu Pembimbing (hanya pembimbing.id_unit_kerja).
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

        $this->getJson('/backend-api/admin/absensi')
            ->assertOk()
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.nama_unit', 'Unit Fallback')
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.pembimbing.pengguna.nama_lengkap', 'Pembimbing Fallback')
            ->assertJsonPath('data.data.0.peserta_magang.pembimbing.pengguna.nama_lengkap', 'Pembimbing Fallback')
            ->assertJsonPath('data.data.0.unit_kerja.pembimbing.pengguna.nama_lengkap', 'Pembimbing Fallback')
            ->assertJsonPath('data.data.0.verifikator.pengguna.nama_lengkap', 'Pembimbing Fallback');

        $this->getJson('/backend-api/admin/logbook')
            ->assertOk()
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.pembimbing.pengguna.nama_lengkap', 'Pembimbing Fallback')
            ->assertJsonPath('data.data.0.peserta_magang.pembimbing.pengguna.nama_lengkap', 'Pembimbing Fallback');

        $this->getJson('/backend-api/admin/pengajuan-izin')
            ->assertOk()
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.pembimbing.pengguna.nama_lengkap', 'Pembimbing Fallback')
            ->assertJsonPath('data.data.0.peserta_magang.pembimbing.pengguna.nama_lengkap', 'Pembimbing Fallback');

        $this->getJson('/backend-api/admin/peserta-magang')
            ->assertOk()
            ->assertJsonPath('data.data.0.pembimbing.pengguna.nama_lengkap', 'Pembimbing Fallback');

        $this->getJson('/backend-api/admin/unit-kerja')
            ->assertOk()
            ->assertJsonPath('data.data.0.pembimbing.pengguna.nama_lengkap', 'Pembimbing Fallback');
    }

    public function test_primary_supervisor_on_unit_takes_precedence_over_reverse_relation(): void
    {
        $admin = $this->createPengguna('admin', 'Admin');
        $unitKerja = $this->createUnit('UNIT-PRIMARY', 'Unit Primary');
        $pembimbingLama = $this->createPembimbing($unitKerja, 'pembimbing-lama', 'Pembimbing Lama');
        $pembimbingUtama = $this->createPembimbing($unitKerja, 'pembimbing-utama', 'Pembimbing Utama');
        $unitKerja->update(['id_pembimbing' => $pembimbingUtama->id]);
        $peserta = $this->createPeserta($unitKerja, 'peserta-primary');

        Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => '2026-11-10 08:00:00',
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/backend-api/admin/absensi')
            ->assertOk()
            ->assertJsonPath('data.data.0.peserta_magang.pembimbing.id', $pembimbingUtama->id)
            ->assertJsonPath('data.data.0.peserta_magang.pembimbing.pengguna.nama_lengkap', 'Pembimbing Utama')
            ->assertJsonPath('data.data.0.unit_kerja.pembimbing.id', $pembimbingUtama->id);

        $this->assertNotSame($pembimbingLama->id, $pembimbingUtama->id);
    }

    public function test_primary_supervisor_is_prioritized_even_when_only_pembimbing_by_unit_is_loaded(): void
    {
        $unitKerja = $this->createUnit('UNIT-PARTIAL', 'Unit Partial');
        $pembimbingTambahan = $this->createPembimbing($unitKerja, 'pembimbing-tambahan', 'Pembimbing Tambahan');
        $pembimbingUtama = $this->createPembimbing($unitKerja, 'pembimbing-utama-partial', 'Pembimbing Utama Partial');
        $unitKerja->update(['id_pembimbing' => $pembimbingUtama->id]);

        // Muat model unit kerja dengan HANYA memuat relasi pembimbingByUnit
        $unitWithOnlyBackupLoaded = UnitKerja::with('pembimbingByUnit')->find($unitKerja->id);
        $this->assertTrue($unitWithOnlyBackupLoaded->relationLoaded('pembimbingByUnit'));
        $this->assertFalse($unitWithOnlyBackupLoaded->relationLoaded('pembimbing'));

        // pembimbingEfektif() harus tetap mengembalikan pembimbing utama
        $efektif = $unitWithOnlyBackupLoaded->pembimbingEfektif();
        $this->assertNotNull($efektif);
        $this->assertSame($pembimbingUtama->id, $efektif->id);
    }

    public function test_admin_can_reassign_primary_supervisor_to_another_unit(): void
    {
        $admin = $this->createPengguna('admin', 'Admin');
        $unitLama = $this->createUnit('UNIT-OLD', 'Unit Lama');
        $unitBaru = $this->createUnit('UNIT-NEW', 'Unit Baru');
        $pembimbing = $this->createPembimbing($unitLama, 'pembimbing-pindah', 'Pembimbing Pindah');
        $unitLama->update(['id_pembimbing' => $pembimbing->id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/backend-api/admin/unit-kerja/'.$unitBaru->id, [
                'id_pembimbing' => $pembimbing->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.pembimbing.pengguna.nama_lengkap', 'Pembimbing Pindah');

        $this->assertDatabaseHas('unit_kerja', ['id' => $unitBaru->id, 'id_pembimbing' => $pembimbing->id]);
        $this->assertDatabaseHas('unit_kerja', ['id' => $unitLama->id, 'id_pembimbing' => null]);
        $this->assertDatabaseHas('pembimbing', ['id' => $pembimbing->id, 'id_unit_kerja' => $unitBaru->id]);
    }

    public function test_admin_moving_supervisor_keeps_unit_primary_column_in_sync(): void
    {
        $admin = $this->createPengguna('admin', 'Admin');
        $unitLama = $this->createUnit('UNIT-OLD', 'Unit Lama');
        $unitBaru = $this->createUnit('UNIT-NEW', 'Unit Baru');
        $pembimbing = $this->createPembimbing($unitLama, 'pembimbing-pindah', 'Pembimbing Pindah');
        $unitLama->update(['id_pembimbing' => $pembimbing->id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/backend-api/admin/pembimbing/'.$pembimbing->id, [
                'id_unit_kerja' => $unitBaru->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('unit_kerja', ['id' => $unitLama->id, 'id_pembimbing' => null]);
        $this->assertDatabaseHas('unit_kerja', ['id' => $unitBaru->id, 'id_pembimbing' => $pembimbing->id]);
        $this->assertDatabaseHas('pembimbing', ['id' => $pembimbing->id, 'id_unit_kerja' => $unitBaru->id]);
    }

    private function createUnit(string $code, string $name): UnitKerja
    {
        return UnitKerja::create([
            'kode_unit' => $code,
            'nama_unit' => $name,
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
    }

    private function createPembimbing(UnitKerja $unitKerja, string $username, string $name): Pembimbing
    {
        $pengguna = $this->createPengguna('pembimbing', $username, $name);

        return Pembimbing::create([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'nip' => 'NIP-'.$username,
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);
    }

    private function createPeserta(UnitKerja $unitKerja, string $username): PesertaMagang
    {
        $pengguna = $this->createPengguna('peserta_magang', $username, 'Peserta '.$username);

        return PesertaMagang::create([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK Contoh',
            'kelas_semester' => 'XII',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);
    }

    private function createPengguna(string $role, string $username, ?string $nama = null): Pengguna
    {
        return Pengguna::create([
            'username' => $username,
            'nama_lengkap' => $nama ?? $username,
            'password' => 'password',
            'role' => $role,
            'status' => 'aktif',
        ]);
    }
}
