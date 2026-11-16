<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Absensi;
use App\Models\Pembimbing;
use App\Models\PengajuanIzin;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_summary_and_only_relevant_izin_for_today(): void
    {
        $admin = $this->createUser('dashboard-admin', 'admin');
        $peserta = $this->createPeserta('dashboard-participant');
        $pesertaLain = $this->createPeserta('dashboard-other-participant');
        $this->createPembimbing('dashboard-supervisor');
        $unitKerja = UnitKerja::create([
            'kode_unit' => 'DASHBOARD-UNIT',
            'nama_unit' => 'Dashboard Unit',
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
        $peserta->update(['id_unit_kerja' => $unitKerja->id]);
        $today = now()->toDateString();

        $waktuAbsensi = now()->startOfDay()->addHours(8);
        $absensiTerbaru = null;
        for ($minute = 0; $minute < 11; $minute++) {
            $absensi = Absensi::create([
                'id_peserta_magang' => $peserta->id,
                'id_unit_kerja' => $unitKerja->id,
                'tanggal_jam' => $waktuAbsensi->copy()->addMinutes($minute),
                'foto_selfie' => "absensi/today-{$minute}.jpg",
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'status' => 'masuk',
            ]);

            if ($minute === 10) {
                $absensiTerbaru = $absensi;
            }
        }
        Absensi::create([
            'id_peserta_magang' => $pesertaLain->id,
            'tanggal_jam' => now()->subDay(),
            'foto_selfie' => 'absensi/yesterday.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
        ]);

        $this->travelTo(now()->subMinutes(10));
        $izinHariIni = $this->createIzin($peserta, $today, $today, 'pending');
        $this->travelTo(now()->addMinutes(10));
        $izinTerbaru = $this->createIzin($peserta, now()->subDay()->toDateString(), $today, 'approved');
        $izinKedaluwarsa = $this->createIzin($pesertaLain, now()->subDays(2)->toDateString(), now()->subDay()->toDateString(), 'pending');
        $izinDitolak = $this->createIzin($pesertaLain, $today, $today, 'rejected');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/backend-api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.jumlah_peserta', 2)
            ->assertJsonPath('data.jumlah_pembimbing', 1)
            ->assertJsonPath('data.total_absensi_hari_ini', 11)
            ->assertJsonCount(10, 'data.detail_absensi_hari_ini')
            ->assertJsonPath('data.detail_absensi_hari_ini.0.id', $absensiTerbaru->id)
            ->assertJsonPath('data.detail_absensi_hari_ini.0.nama_peserta', 'dashboard-participant')
            ->assertJsonPath('data.detail_absensi_hari_ini.0.nama_unit', 'Dashboard Unit')
            ->assertJsonPath('data.detail_absensi_hari_ini.0.unit_kerja.nama_unit', 'Dashboard Unit')
            ->assertJsonPath('data.detail_absensi_hari_ini.0.tanggal_jam', $absensiTerbaru->tanggal_jam->toISOString())
            ->assertJsonPath('data.detail_absensi_hari_ini.0.status', 'masuk')
            ->assertJsonCount(2, 'data.izin_terbaru')
            ->assertJsonPath('data.izin_terbaru.0.id', $izinTerbaru->id)
            ->assertJsonPath('data.izin_terbaru.0.status', 'approved')
            ->assertJsonPath('data.izin_terbaru.1.id', $izinHariIni->id);

        $izinIds = collect($response->json('data.izin_terbaru'))->pluck('id');
        $this->assertFalse($izinIds->contains($izinKedaluwarsa->id));
        $this->assertFalse($izinIds->contains($izinDitolak->id));
    }

    public function test_dashboard_requires_an_admin_user(): void
    {
        $this->getJson('/backend-api/admin/dashboard')->assertUnauthorized();

        $peserta = $this->createUser('dashboard-non-admin', 'peserta_magang');

        $this->actingAs($peserta, 'sanctum')
            ->getJson('/backend-api/admin/dashboard')
            ->assertForbidden();
    }

    private function createUser(string $username, string $role): Pengguna
    {
        return Pengguna::create([
            'username' => $username,
            'nama_lengkap' => $username,
            'password' => 'password',
            'role' => $role,
            'status' => 'aktif',
        ]);
    }

    private function createPeserta(string $username): PesertaMagang
    {
        $pengguna = $this->createUser($username, 'peserta_magang');

        return PesertaMagang::create([
            'id_pengguna' => $pengguna->id,
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

    private function createPembimbing(string $username): Pembimbing
    {
        $pengguna = $this->createUser($username, 'pembimbing');

        return Pembimbing::create([
            'id_pengguna' => $pengguna->id,
            'nip' => $username,
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);
    }

    private function createIzin(PesertaMagang $peserta, string $tanggalMulai, string $tanggalSelesai, string $status): PengajuanIzin
    {
        return PengajuanIzin::create([
            'id_peserta_magang' => $peserta->id,
            'jenis' => 'izin',
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalSelesai,
            'keterangan' => 'Keterangan izin.',
            'status' => $status,
        ]);
    }
}
