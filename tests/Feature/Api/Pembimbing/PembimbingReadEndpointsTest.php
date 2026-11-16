<?php

namespace Tests\Feature\Api\Pembimbing;

use App\Models\Absensi;
use App\Models\Logbook;
use App\Models\Pembimbing;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PembimbingReadEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rekan_endpoint_returns_all_supervisors_with_user_and_unit_details(): void
    {
        $ownUnit = $this->createUnit('UNIT-OWN');
        $otherUnit = $this->createUnit('UNIT-OTHER');
        $pembimbing = $this->createPembimbing($ownUnit, 'pembimbing-own', 'Pembimbing Sendiri');
        $colleague = $this->createPembimbing($otherUnit, 'pembimbing-other', 'Pembimbing Rekan');
        $this->createParticipant($otherUnit, 'peserta-rekan-1');
        $this->createParticipant($otherUnit, 'peserta-rekan-2');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/rekan')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.total_bimbingan', 0)
            ->assertJsonPath('data.data.1.nip', 'NIP-pembimbing-other')
            ->assertJsonPath('data.data.1.pengguna.nama_lengkap', 'Pembimbing Rekan')
            ->assertJsonPath('data.data.1.unit_kerja.nama_unit', 'UNIT-OTHER')
            ->assertJsonPath('data.data.1.total_bimbingan', 2)
            ->assertJsonCount(2, 'data.data.1.daftar_peserta_bimbingan')
            ->assertJsonPath(
                'data.data.1.daftar_peserta_bimbingan.0.pengguna.nama_lengkap',
                'Peserta peserta-rekan-1',
            )
            ->assertJsonPath(
                'data.data.1.daftar_peserta_bimbingan.0.asal_sekolah_kampus',
                'SMK UNIT-OTHER',
            )
            ->assertJsonCount(0, 'data.data.0.daftar_peserta_bimbingan');
    }

    public function test_pembimbing_can_list_all_units_for_monitoring_filter(): void
    {
        $zetaUnit = $this->createUnit('UNIT-ZETA');
        $alphaUnit = $this->createUnit('UNIT-ALPHA');
        $pembimbing = $this->createPembimbing($zetaUnit, 'pembimbing-filter');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/unit-kerja')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    ['id' => $alphaUnit->id, 'nama_unit' => 'UNIT-ALPHA'],
                    ['id' => $zetaUnit->id, 'nama_unit' => 'UNIT-ZETA'],
                ],
            ]);
    }

    public function test_peserta_bimbingan_endpoint_returns_only_same_unit_participants_and_documents(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $ownUnit = $this->createUnit('UNIT-OWN');
        $otherUnit = $this->createUnit('UNIT-OTHER');
        $pembimbing = $this->createPembimbing($ownUnit, 'pembimbing-own');
        $ownParticipant = $this->createParticipant($ownUnit, 'peserta-own', [
            'file_surat_permohonan' => 'dokumen/own-permohonan.pdf',
            'file_surat_balasan' => 'dokumen/own-balasan.pdf',
            'pembimbing_akademik' => 'Dr. Akademik',
            'nip_pembimbing_akademik' => 'NIDN-12345',
            'no_hp_pembimbing_akademik' => '08123456789',
        ]);
        $this->createParticipant($otherUnit, 'peserta-other', [
            'file_surat_permohonan' => 'dokumen/other-permohonan.pdf',
            'file_surat_balasan' => 'dokumen/other-balasan.pdf',
        ]);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/peserta-bimbingan')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('my_unit_kerja_id', $ownUnit->id)
            ->assertJsonPath('data.data.0.id', $ownParticipant->id)
            ->assertJsonPath('data.data.0.file_surat_permohonan', 'dokumen/own-permohonan.pdf')
            ->assertJsonPath('data.data.0.file_surat_balasan', 'dokumen/own-balasan.pdf')
            ->assertJsonPath('data.data.0.has_surat_permohonan', true)
            ->assertJsonPath('data.data.0.has_surat_balasan', true)
            ->assertJsonPath('data.data.0.nama_lengkap', 'Peserta peserta-own')
            ->assertJsonPath('data.data.0.pembimbing_akademik', 'Dr. Akademik')
            ->assertJsonPath('data.data.0.nip_pembimbing_akademik', 'NIDN-12345')
            ->assertJsonPath('data.data.0.no_hp_pembimbing_akademik', '08123456789')
            ->assertJsonPath('data.data.0.absensi_terbaru', null)
            ->assertJsonPath('data.data.0.logbook_terakhir', null)
            ->assertJsonPath('data.data.0.status_presensi_hari_ini', 'Belum Absen')
            ->assertJsonPath('data.data.0.status_logbook_hari_ini', 'Belum Diisi')
            ->assertJsonCount(0, 'data.data.0.absensi_hari_ini')
            ->assertJsonCount(0, 'data.data.0.logbook_hari_ini');
    }

    public function test_peserta_bimbingan_includes_latest_attendance_and_logbook_data(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00'));

        $unitKerja = $this->createUnit('UNIT-MONITORING');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-monitoring');
        $peserta = $this->createParticipant($unitKerja, 'peserta-monitoring', [
            'pembimbing_akademik' => 'Dr. Akademik',
            'nip_pembimbing_akademik' => 'NIDN-67890',
            'no_hp_pembimbing_akademik' => '081122233344',
        ]);
        Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => now()->subDay(),
            'foto_selfie' => 'absensi/yesterday.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'keluar',
            'status_verifikasi' => 'approved',
        ]);
        $checkIn = Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => now(),
            'foto_selfie' => 'absensi/today.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'pending',
        ]);
        $checkOut = Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => now()->addHour(),
            'foto_selfie' => 'absensi/today-checkout.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'keluar',
            'status_verifikasi' => 'pending',
        ]);
        Logbook::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal' => now()->subDay()->toDateString(),
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:00:00',
            'kegiatan' => 'Kegiatan sebelumnya',
            'status_verifikasi' => 'rejected',
        ]);
        $latestLogbook = Logbook::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal' => now()->toDateString(),
            'jam_mulai' => '09:00:00',
            'jam_selesai' => '10:00:00',
            'kegiatan' => 'Kegiatan terbaru',
            'status_verifikasi' => 'approved',
        ]);
        $pendingParticipant = $this->createParticipant($unitKerja, 'peserta-pending', [
            'updated_at' => now()->subMinute(),
        ]);
        Logbook::create([
            'id_peserta_magang' => $pendingParticipant->id,
            'tanggal' => now()->toDateString(),
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:00:00',
            'kegiatan' => 'Kegiatan menunggu persetujuan',
            'status_verifikasi' => 'pending',
        ]);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/peserta-bimbingan')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonFragment([
                'id' => $peserta->id,
                'id_peserta_magang' => $peserta->id,
                'nama_lengkap' => 'Peserta peserta-monitoring',
                'asal_sekolah_kampus' => 'SMK UNIT-MONITORING',
                'pembimbing_akademik' => 'Dr. Akademik',
                'nip_pembimbing_akademik' => 'NIDN-67890',
                'no_hp_pembimbing_akademik' => '081122233344',
                'status_presensi_hari_ini' => 'Pulang',
                'status_verifikasi_presensi_hari_ini' => 'pending',
                'status_logbook_hari_ini' => 'Approved',
            ])
            ->assertJsonFragment([
                'id' => $pendingParticipant->id,
                'status_presensi_hari_ini' => 'Belum Absen',
                'status_logbook_hari_ini' => 'Pending',
            ])
            ->assertJsonPath('data.data.0.absensi_hari_ini.0.id', $checkOut->id)
            ->assertJsonPath('data.data.0.absensi_hari_ini.1.id', $checkIn->id)
            ->assertJsonPath('data.data.0.absensi_terbaru.id', $checkOut->id)
            ->assertJsonPath('data.data.0.logbook_hari_ini.0.id', $latestLogbook->id)
            ->assertJsonPath('data.data.0.logbook_terakhir.id', $latestLogbook->id)
            ->assertJsonCount(2, 'data.data.0.absensi_hari_ini')
            ->assertJsonCount(1, 'data.data.0.logbook_hari_ini');
    }

    public function test_monitoring_returns_unique_participants_and_only_today_attendance_and_logbook(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));

        $unitKerja = $this->createUnit('UNIT-MONITORING');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-monitoring');
        $presentParticipant = $this->createParticipant($unitKerja, 'peserta-hadir');
        $absentParticipant = $this->createParticipant($unitKerja, 'peserta-belum-hadir', [
            'updated_at' => now()->subMinute(),
        ]);
        $checkIn = $this->createAbsensi($presentParticipant, $unitKerja);
        $checkOut = Absensi::create([
            'id_peserta_magang' => $presentParticipant->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => now()->addHour(),
            'foto_selfie' => 'absensi/checkout.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'keluar',
            'status_verifikasi' => 'pending',
        ]);
        Absensi::create([
            'id_peserta_magang' => $absentParticipant->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => now()->subDay(),
            'foto_selfie' => 'absensi/yesterday.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'pending',
        ]);
        Logbook::create([
            'id_peserta_magang' => $presentParticipant->id,
            'tanggal' => now()->toDateString(),
            'jam_mulai' => '09:00:00',
            'jam_selesai' => '10:00:00',
            'kegiatan' => 'Kegiatan hari ini',
            'status_verifikasi' => 'pending',
        ]);
        Logbook::create([
            'id_peserta_magang' => $absentParticipant->id,
            'tanggal' => now()->subDay()->toDateString(),
            'jam_mulai' => '09:00:00',
            'jam_selesai' => '10:00:00',
            'kegiatan' => 'Kegiatan kemarin',
            'status_verifikasi' => 'approved',
        ]);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/monitoring')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.id', $presentParticipant->id)
            ->assertJsonPath('data.data.0.status_presensi', 'Masuk')
            ->assertJsonPath('data.data.0.status_presensi_hari_ini', 'Pulang')
            ->assertJsonPath('data.data.0.status_logbook_hari_ini', 'Pending')
            ->assertJsonPath('data.data.0.absensi_hari_ini.0.id', $checkOut->id)
            ->assertJsonPath('data.data.0.absensi_hari_ini.1.id', $checkIn->id)
            ->assertJsonCount(2, 'data.data.0.absensi_hari_ini')
            ->assertJsonCount(1, 'data.data.0.logbook_hari_ini')
            ->assertJsonPath('data.data.1.id', $absentParticipant->id)
            ->assertJsonPath('data.data.1.status_presensi', 'Belum Absen')
            ->assertJsonPath('data.data.1.status_presensi_hari_ini', 'Belum Absen')
            ->assertJsonPath('data.data.1.status_logbook_hari_ini', 'Belum Diisi')
            ->assertJsonCount(0, 'data.data.1.absensi_hari_ini')
            ->assertJsonCount(0, 'data.data.1.logbook_hari_ini');
    }

    public function test_rekap_absensi_returns_paginated_attendance_rows_for_unit_and_date_range(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));

        $ownUnit = $this->createUnit('UNIT-OWN');
        $otherUnit = $this->createUnit('UNIT-OTHER');
        $pembimbing = $this->createPembimbing($ownUnit, 'pembimbing-own');
        $ownParticipant = $this->createParticipant($ownUnit, 'peserta-own');
        $otherParticipant = $this->createParticipant($otherUnit, 'peserta-other');
        $morningAttendance = $this->createAbsensi($ownParticipant, $ownUnit, '2026-11-16 08:00:00');
        $endOfDayAttendance = $this->createAbsensi($ownParticipant, $ownUnit, '2026-11-16 23:59:59');
        $this->createAbsensi($ownParticipant, $ownUnit, '2026-11-15 08:00:00');
        $this->createAbsensi($ownParticipant, $otherUnit, '2026-11-16 10:00:00');
        $this->createAbsensi($otherParticipant, $ownUnit, '2026-11-16 11:00:00');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/rekap-absensi?tanggal_mulai=2026-11-16&tanggal_selesai=2026-11-16')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.id', $endOfDayAttendance->id)
            ->assertJsonPath('data.data.0.id_unit_kerja', $ownUnit->id)
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta peserta-own')
            ->assertJsonPath('data.data.0.peserta_magang.unit_kerja.nama_unit', 'UNIT-OWN')
            ->assertJsonPath('data.data.1.id', $morningAttendance->id)
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_rekap_absensi_and_legacy_monitoring_ignore_other_unit_filters(): void
    {
        $ownUnit = $this->createUnit('UNIT-OWN');
        $otherUnit = $this->createUnit('UNIT-OTHER');
        $pembimbing = $this->createPembimbing($ownUnit, 'pembimbing-own');
        $ownParticipant = $this->createParticipant($ownUnit, 'peserta-own');
        $otherParticipant = $this->createParticipant($otherUnit, 'peserta-other');
        $ownAbsensi = $this->createAbsensi($ownParticipant, $ownUnit);
        $this->createAbsensi($otherParticipant, $ownUnit);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/rekap-absensi?unit_saya_saja=false&id_unit_kerja='.$otherUnit->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $ownAbsensi->id)
            ->assertJsonPath('data.data.0.peserta_magang.id', $ownParticipant->id);

        $this->getJson('/backend-api/monitoring?unit_saya_saja=false&id_unit_kerja='.$otherUnit->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $ownParticipant->id)
            ->assertJsonPath('data.data.0.absensi_hari_ini.0.id', $ownAbsensi->id);
    }

    public function test_pembimbing_cannot_download_or_upload_documents_for_another_unit(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $ownUnit = $this->createUnit('UNIT-OWN');
        $otherUnit = $this->createUnit('UNIT-OTHER');
        $pembimbing = $this->createPembimbing($ownUnit, 'pembimbing-own');
        $otherParticipant = $this->createParticipant($otherUnit, 'peserta-other', [
            'file_surat_permohonan' => 'dokumen/other-permohonan.pdf',
            'file_surat_balasan' => 'dokumen/other-balasan.pdf',
        ]);
        Storage::disk('public')->put('dokumen/other-permohonan.pdf', 'surat permohonan');
        Storage::disk('public')->put('dokumen/other-balasan.pdf', 'surat balasan');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->get("/backend-api/download-surat-permohonan/{$otherParticipant->id}")
            ->assertNotFound();

        $this->post("/backend-api/upload-surat-balasan/{$otherParticipant->id}", [
            'file_surat_balasan' => UploadedFile::fake()->createWithContent(
                'surat-balasan.pdf',
                "%PDF-1.4\nSurat balasan\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->assertDatabaseHas('peserta_magang', [
            'id' => $otherParticipant->id,
            'file_surat_balasan' => 'dokumen/other-balasan.pdf',
        ]);
    }

    public function test_pembimbing_can_download_and_upload_documents_for_their_unit(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $unitKerja = $this->createUnit('UNIT-OWN');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-own');
        $participant = $this->createParticipant($unitKerja, 'peserta-own', [
            'file_surat_permohonan' => 'dokumen/own-permohonan.pdf',
        ]);
        Storage::disk('public')->put('dokumen/own-permohonan.pdf', 'surat permohonan');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->get("/backend-api/download-surat-permohonan/{$participant->id}")
            ->assertOk();

        $this->post("/backend-api/upload-surat-balasan/{$participant->id}", [
            'file_surat_balasan' => UploadedFile::fake()->createWithContent(
                'surat-balasan.pdf',
                "%PDF-1.4\nSurat balasan\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'lengkap');

        $this->assertDatabaseHas('peserta_magang', [
            'id' => $participant->id,
            'status_dokumen' => 'lengkap',
        ]);
        Storage::disk('public')->assertExists($participant->fresh()->file_surat_balasan);
    }

    public function test_new_endpoints_return_401_without_authentication_and_403_for_participants(): void
    {
        $endpoints = [
            '/backend-api/pembimbing/rekan',
            '/backend-api/pembimbing/peserta-bimbingan',
            '/backend-api/pembimbing/rekap-absensi',
            '/backend-api/pembimbing/unit-kerja',
            '/backend-api/pembimbing/laporan?jenis_laporan=presensi',
        ];

        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }

        $participantUnit = $this->createUnit('UNIT-PARTICIPANT');
        $participant = $this->createParticipant($participantUnit, 'peserta-role');

        $this->actingAs($participant->pengguna, 'sanctum');

        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint)->assertForbidden();
        }
    }

    private function createUnit(string $code): UnitKerja
    {
        return UnitKerja::create([
            'kode_unit' => $code,
            'nama_unit' => $code,
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
    }

    private function createPembimbing(
        UnitKerja $unitKerja,
        string $username,
        ?string $name = null,
    ): Pembimbing {
        $pengguna = Pengguna::create([
            'username' => $username,
            'nama_lengkap' => $name ?? 'Pembimbing '.$username,
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);

        return Pembimbing::create([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'nip' => 'NIP-'.$username,
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);
    }

    private function createParticipant(UnitKerja $unitKerja, string $username, array $attributes = []): PesertaMagang
    {
        $pengguna = Pengguna::create([
            'username' => $username,
            'nama_lengkap' => 'Peserta '.$username,
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);

        return PesertaMagang::create(array_merge([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK '.$unitKerja->kode_unit,
            'kelas_semester' => 'XII',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ], $attributes));
    }

    private function createAbsensi(
        PesertaMagang $peserta,
        UnitKerja $storedUnit,
        ?string $tanggalJam = null,
    ): Absensi {
        return Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $storedUnit->id,
            'tanggal_jam' => $tanggalJam ?? now()->addSeconds($peserta->id),
            'foto_selfie' => 'absensi/selfie-'.$peserta->id.'.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'pending',
        ]);
    }
}
