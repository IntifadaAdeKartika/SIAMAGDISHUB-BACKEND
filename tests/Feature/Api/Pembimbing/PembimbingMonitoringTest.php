<?php

namespace Tests\Feature\Api\Pembimbing;

use App\Models\Absensi;
use App\Models\Logbook;
use App\Models\Pembimbing;
use App\Models\PengajuanIzin;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PembimbingMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_pembimbing_report_filters_attendance_by_unit_participant_and_date_range(): void
    {
        $unitKerja = $this->createUnitKerja('UNIT-01');
        $unitKerjaLain = $this->createUnitKerja('UNIT-02');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-1');
        $peserta = $this->createPesertaMagang($unitKerja, 'peserta-target', 'Peserta Target');
        $pesertaLain = $this->createPesertaMagang($unitKerja, 'peserta-lain', 'Peserta Lain');
        $pesertaUnitLain = $this->createPesertaMagang($unitKerjaLain, 'peserta-unit-lain', 'Peserta Unit Lain');
        $absensiTarget = $this->createAbsensi($peserta, '2026-11-12 08:00:00');
        $absensiTargetEndOfDay = $this->createAbsensi($peserta, '2026-11-12 23:59:59');
        $this->createAbsensi($peserta, '2026-11-10 08:00:00');
        $absensiPesertaLain = $this->createAbsensi($pesertaLain, '2026-11-12 09:00:00');
        $this->createAbsensi($pesertaUnitLain, '2026-11-12 08:00:00');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/laporan?jenis_laporan=kehadiran&tanggal_mulai=2026-11-12&tanggal_selesai=2026-11-12')
            ->assertOk()
            ->assertJsonPath('jenis_laporan', 'presensi')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $absensiTargetEndOfDay->id)
            ->assertJsonPath('data.1.id', $absensiPesertaLain->id)
            ->assertJsonPath('data.2.id', $absensiTarget->id)
            ->assertJsonPath('pagination.total', 3);

        $this->getJson('/backend-api/pembimbing/laporan?jenis_laporan=presensi&peserta_id='.$peserta->id.'&tanggal_mulai=2026-11-12&tanggal_selesai=2026-11-12')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $absensiTargetEndOfDay->id)
            ->assertJsonPath('data.1.id', $absensiTarget->id)
            ->assertJsonPath('data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Target');
    }

    public function test_pembimbing_report_filters_logbook_by_unit_participant_and_date_range(): void
    {
        $unitKerja = $this->createUnitKerja('UNIT-01');
        $unitKerjaLain = $this->createUnitKerja('UNIT-02');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-1');
        $peserta = $this->createPesertaMagang($unitKerja, 'peserta-target', 'Peserta Target');
        $pesertaLain = $this->createPesertaMagang($unitKerja, 'peserta-lain', 'Peserta Lain');
        $pesertaUnitLain = $this->createPesertaMagang($unitKerjaLain, 'peserta-unit-lain', 'Peserta Unit Lain');
        $logbookTarget = $this->createLogbook($peserta, 'Logbook target', '2026-11-12');
        $this->createLogbook($peserta, 'Logbook di luar rentang', '2026-11-10');
        $logbookPesertaLain = $this->createLogbook($pesertaLain, 'Logbook peserta lain', '2026-11-12');
        $this->createLogbook($pesertaUnitLain, 'Logbook unit lain', '2026-11-12');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/laporan?jenis_laporan=logbook&tanggal_mulai=2026-11-12&tanggal_selesai=2026-11-12')
            ->assertOk()
            ->assertJsonPath('jenis_laporan', 'logbook')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $logbookPesertaLain->id)
            ->assertJsonPath('data.1.id', $logbookTarget->id)
            ->assertJsonPath('pagination.total', 2);

        $this->getJson('/backend-api/pembimbing/laporan?jenis_laporan=logbook&peserta_id='.$peserta->id.'&tanggal_mulai=2026-11-12&tanggal_selesai=2026-11-12')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $logbookTarget->id)
            ->assertJsonPath('data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Target')
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_pembimbing_report_rejects_an_unsupported_report_type(): void
    {
        $unitKerja = $this->createUnitKerja('UNIT-01');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-1');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/laporan?jenis_laporan=izin')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['jenis_laporan']);
    }

    public function test_pembimbing_report_rejects_a_reversed_date_range(): void
    {
        $unitKerja = $this->createUnitKerja('UNIT-01');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-1');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/laporan?jenis_laporan=presensi&tanggal_mulai=2026-11-13&tanggal_selesai=2026-11-12')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai']);
    }

    public function test_pembimbing_only_sees_logbooks_and_leave_requests_from_their_unit(): void
    {
        $unitKerja = $this->createUnitKerja('UNIT-01');
        $unitKerjaLain = $this->createUnitKerja('UNIT-02');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-1');
        $peserta = $this->createPesertaMagang($unitKerja, 'peserta-1', 'Peserta Sendiri');
        $pesertaLain = $this->createPesertaMagang($unitKerjaLain, 'peserta-2', 'Peserta Lain', 'pending');

        $logbookSendiri = $this->createLogbook($peserta, 'Logbook peserta bimbingan');
        $this->createLogbook($pesertaLain, 'Logbook peserta unit lain');
        $izinSendiri = $this->createPengajuanIzin($peserta, 'Izin peserta bimbingan');
        $this->createPengajuanIzin($pesertaLain, 'Izin peserta unit lain');

        $this->actingAs($pembimbing->pengguna, 'sanctum');

        $this->getJson('/backend-api/pembimbing/logbook')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $logbookSendiri->id)
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Sendiri')
            ->assertJsonPath('data.data.0.peserta_magang.asal_sekolah_kampus', 'SMK UNIT-01');
        $this->getJson('/backend-api/pembimbing/pengajuan-izin')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $izinSendiri->id)
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Sendiri')
            ->assertJsonPath('data.data.0.peserta_magang.asal_sekolah_kampus', 'SMK UNIT-01');
    }

    private function createUnitKerja(string $kodeUnit): UnitKerja
    {
        return UnitKerja::create([
            'kode_unit' => $kodeUnit,
            'nama_unit' => $kodeUnit,
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
    }

    private function createPembimbing(UnitKerja $unitKerja, string $username): Pembimbing
    {
        $pengguna = Pengguna::create([
            'username' => $username,
            'nama_lengkap' => 'Pembimbing '.$username,
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);

        return Pembimbing::create([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'nip' => $username,
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);
    }

    private function createPesertaMagang(
        UnitKerja $unitKerja,
        string $username,
        string $nama,
        string $statusPeserta = 'aktif',
    ): PesertaMagang {
        $pengguna = Pengguna::create([
            'username' => $username,
            'nama_lengkap' => $nama,
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);

        return PesertaMagang::create([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK '.$unitKerja->kode_unit,
            'kelas_semester' => 'XII',
            'status_peserta' => $statusPeserta,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);
    }

    private function createLogbook(
        PesertaMagang $peserta,
        string $kegiatan,
        string $tanggal = '2026-11-11',
    ): Logbook {
        return Logbook::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal' => $tanggal,
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:00:00',
            'kegiatan' => $kegiatan,
        ]);
    }

    private function createAbsensi(PesertaMagang $peserta, string $tanggalJam): Absensi
    {
        return Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $peserta->id_unit_kerja,
            'tanggal_jam' => $tanggalJam,
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'pending',
        ]);
    }

    private function createPengajuanIzin(PesertaMagang $peserta, string $keterangan): PengajuanIzin
    {
        return PengajuanIzin::create([
            'id_peserta_magang' => $peserta->id,
            'jenis' => 'izin',
            'tanggal_mulai' => '2026-11-12',
            'tanggal_selesai' => '2026-11-12',
            'keterangan' => $keterangan,
        ]);
    }
}
